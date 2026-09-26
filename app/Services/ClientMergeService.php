<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class ClientMergeService
{
    /**
     * Search clients eligible for merge.
     */
    public function searchClients(?string $search = null)
    {
        $query = DB::table('users');

        if (Schema::hasColumn('users', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $query->where(function ($q) {
            $q->whereNull('status')
              ->orWhereRaw('LOWER(TRIM(status)) NOT IN (?, ?)', ['deleted', 'merged']);
        });

        if (!empty($search)) {
            $search = trim($search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('company_name', 'like', "%{$search}%");

                if (Schema::hasColumn('users', 'phone_number')) {
                    $q->orWhere('phone_number', 'like', "%{$search}%");
                }
                if (Schema::hasColumn('users', 'mobile')) {
                    $q->orWhere('mobile', 'like', "%{$search}%");
                }
                if (Schema::hasColumn('users', 'phone')) {
                    $q->orWhere('phone', 'like', "%{$search}%");
                }
            });
        }

        $users = $query->orderBy('created_at', 'desc')->limit(50)->get();

        $userIDs = $users->pluck('id')->toArray();
        $stats = DB::table('pcb_orders')
            ->select('user_id', DB::raw('COUNT(id) as orders_count'), DB::raw('COALESCE(SUM(order_value), 0) as total_spent'))
            ->whereIn('user_id', $userIDs)
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        return $users->map(function ($u) use ($stats) {
            $st = $stats->get($u->id);
            return [
                'id' => (int)$u->id,
                'name' => $u->name ?? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                'first_name' => $u->first_name ?? '',
                'last_name' => $u->last_name ?? '',
                'email' => $u->email,
                'phone_number' => $u->phone_number ?? $u->mobile ?? $u->phone ?? '',
                'company_name' => $u->company_name ?? '',
                'status' => $u->status ?? 'Active',
                'orders_count' => $st ? (int)$st->orders_count : 0,
                'total_spent' => $st ? (float)$st->total_spent : 0.0,
                'available_credits' => (int)($u->available_credits ?? 0),
                'country' => $u->country ?? 'India',
                'created_at' => $u->created_at ?? null,
            ];
        });
    }

    /**
     * Generate preview of merge operation impact & conflicts.
     */
    public function getMergePreview(array $sourceIds, int $targetId): array
    {
        $this->validateMergeParticipants($sourceIds, $targetId);

        $targetUser = DB::table('users')->where('id', $targetId)->first();
        $sourceUsers = DB::table('users')->whereIn('id', $sourceIds)->get();

        $targetStats = $this->getClientRecordStats($targetId);
        $sourceStatsList = [];
        $totalSourceStats = [
            'orders_count' => 0,
            'total_spent' => 0.0,
            'payments_count' => 0,
            'addresses_count' => 0,
            'gerbers_count' => 0,
            'tickets_count' => 0,
            'available_credits' => 0,
        ];

        foreach ($sourceUsers as $src) {
            $st = $this->getClientRecordStats($src->id);
            $st['user'] = $this->formatUserObj($src);
            $sourceStatsList[] = $st;

            $totalSourceStats['orders_count'] += $st['orders_count'];
            $totalSourceStats['total_spent'] += $st['total_spent'];
            $totalSourceStats['payments_count'] += $st['payments_count'];
            $totalSourceStats['addresses_count'] += $st['addresses_count'];
            $totalSourceStats['gerbers_count'] += $st['gerbers_count'];
            $totalSourceStats['tickets_count'] += $st['tickets_count'];
            $totalSourceStats['available_credits'] += (int)($src->available_credits ?? 0);
        }

        $projectedTotals = [
            'orders_count' => $targetStats['orders_count'] + $totalSourceStats['orders_count'],
            'total_spent' => $targetStats['total_spent'] + $totalSourceStats['total_spent'],
            'payments_count' => $targetStats['payments_count'] + $totalSourceStats['payments_count'],
            'addresses_count' => $targetStats['addresses_count'] + $totalSourceStats['addresses_count'],
            'gerbers_count' => $targetStats['gerbers_count'] + $totalSourceStats['gerbers_count'],
            'tickets_count' => $targetStats['tickets_count'] + $totalSourceStats['tickets_count'],
            'available_credits' => (int)($targetUser->available_credits ?? 0) + $totalSourceStats['available_credits'],
        ];

        // Profile Conflicts Identification
        $conflicts = [];
        $fieldsToCheck = [
            'company_name' => 'Company Name',
            'phone_number' => 'Phone Number',
            'name' => 'Full Name',
            'country' => 'Country',
            'gstin' => 'GSTIN Number'
        ];

        foreach ($fieldsToCheck as $field => $label) {
            $targetVal = trim((string)($targetUser->{$field} ?? ''));
            $sourceVals = [];

            foreach ($sourceUsers as $src) {
                $sVal = trim((string)($src->{$field} ?? ''));
                if (!empty($sVal) && $sVal !== $targetVal) {
                    $sourceVals[$src->id] = [
                        'user_id' => $src->id,
                        'user_name' => $src->name ?? $src->email,
                        'value' => $sVal
                    ];
                }
            }

            if (!empty($sourceVals)) {
                $conflicts[] = [
                    'field' => $field,
                    'label' => $label,
                    'target_value' => $targetVal,
                    'source_values' => array_values($sourceVals)
                ];
            }
        }

        return [
            'target' => array_merge($this->formatUserObj($targetUser), $targetStats),
            'sources' => $sourceStatsList,
            'counts' => [
                'sources_count' => count($sourceUsers),
                'orders_count' => $totalSourceStats['orders_count'],
                'payments_count' => $totalSourceStats['payments_count'],
                'addresses_count' => $totalSourceStats['addresses_count'],
                'gerber_files_count' => $totalSourceStats['gerbers_count'],
                'support_tickets_count' => $totalSourceStats['tickets_count'],
                'logs_count' => 0,
                'total_available_credits' => $totalSourceStats['available_credits'],
                'total_bonus_credits' => 0,
            ],
            'total_source_stats' => $totalSourceStats,
            'projected_totals' => $projectedTotals,
            'conflicts' => array_map(function ($c) {
                return [
                    'field' => $c['field'],
                    'label' => $c['label'],
                    'target_value' => $c['target_value'],
                    'sources_values' => array_map(function ($sv) {
                        return [
                            'source_id' => $sv['user_id'],
                            'name' => $sv['user_name'],
                            'value' => $sv['value']
                        ];
                    }, $c['source_values']),
                    'has_conflict' => true
                ];
            }, $conflicts)
        ];
    }

    /**
     * Execute transactional client merge.
     */
    public function executeMerge(array $sourceIds, int $targetId, array $conflictResolutions = [], string $confirmation = 'MERGE', ?int $adminId = null, ?string $adminName = null): array
    {
        if (strtoupper(trim($confirmation)) !== 'MERGE') {
            throw new Exception("Confirmation string must be 'MERGE'.");
        }

        $this->validateMergeParticipants($sourceIds, $targetId);

        return DB::transaction(function () use ($sourceIds, $targetId, $conflictResolutions, $adminId, $adminName) {
            // Lock rows
            $targetUser = DB::table('users')->where('id', $targetId)->lockForUpdate()->first();
            $sourceUsers = DB::table('users')->whereIn('id', $sourceIds)->lockForUpdate()->get();

            if (!$targetUser || count($sourceUsers) !== count($sourceIds)) {
                throw new Exception("One or more clients participating in the merge could not be locked.");
            }

            $ordersMigrated = DB::table('pcb_orders')->whereIn('user_id', $sourceIds)->count();
            $paymentsMigrated = DB::table('payment_transactions')->whereIn('user_id', $sourceIds)->count();
            $addressesMigrated = DB::table('user_addresses')->whereIn('user_id', $sourceIds)->count();
            $gerbersMigrated = DB::table('gerber_files')->whereIn('user_id', $sourceIds)->count();
            $ticketsMigrated = DB::table('support_tickets')->whereIn('user_id', $sourceIds)->count();

            // 1. Reassign Orders
            DB::table('pcb_orders')->whereIn('user_id', $sourceIds)->update(['user_id' => $targetId, 'updated_at' => now()]);

            // 2. Reassign Payments
            DB::table('payment_transactions')->whereIn('user_id', $sourceIds)->update(['user_id' => $targetId, 'updated_at' => now()]);

            // 3. Reassign Addresses (set is_default=false so primary default address of target is kept)
            DB::table('user_addresses')->whereIn('user_id', $sourceIds)->update(['user_id' => $targetId, 'is_default' => false, 'updated_at' => now()]);

            // 4. Reassign Gerber files
            DB::table('gerber_files')->whereIn('user_id', $sourceIds)->update(['user_id' => $targetId, 'updated_at' => now()]);

            // 5. Reassign Support Tickets
            DB::table('support_tickets')->whereIn('user_id', $sourceIds)->update(['user_id' => $targetId, 'updated_at' => now()]);

            // 6. Reassign Order Logs
            DB::table('pcb_order_logs')->whereIn('user_id', $sourceIds)->update(['user_id' => $targetId, 'updated_at' => now()]);

            // 7. Reassign Notifications if notification table exists & has user_id
            if (Schema::hasTable('notifications') && Schema::hasColumn('notifications', 'user_id')) {
                DB::table('notifications')->whereIn('user_id', $sourceIds)->update(['user_id' => $targetId, 'updated_at' => now()]);
            }

            // 8. Reassign Email Logs if email_logs table exists
            if (Schema::hasTable('email_logs')) {
                if (Schema::hasColumn('email_logs', 'customer_id')) {
                    DB::table('email_logs')->whereIn('customer_id', $sourceIds)->update(['customer_id' => $targetId]);
                }
                if (Schema::hasColumn('email_logs', 'user_id')) {
                    DB::table('email_logs')->whereIn('user_id', $sourceIds)->update(['user_id' => $targetId]);
                }
            }

            // 9. Reassign Carts if carts table & user_id column exist
            if (Schema::hasTable('carts') && Schema::hasColumn('carts', 'user_id')) {
                DB::table('carts')->whereIn('user_id', $sourceIds)->update(['user_id' => $targetId]);
            }

            // 10. Update Target Profile Conflict Resolutions & Aggregate Credits
            $targetUpdates = [];
            $targetCols = Schema::getColumnListing('users');

            foreach ($conflictResolutions as $field => $chosenSourceId) {
                if ($chosenSourceId !== 'target' && is_numeric($chosenSourceId) && in_array($field, $targetCols)) {
                    $srcObj = $sourceUsers->firstWhere('id', (int)$chosenSourceId);
                    if ($srcObj && !empty($srcObj->{$field})) {
                        $targetUpdates[$field] = $srcObj->{$field};
                    }
                }
            }

            // Aggregate credits
            $addedCredits = 0;
            $addedBonus = 0;
            foreach ($sourceUsers as $src) {
                $addedCredits += (int)($src->available_credits ?? 0);
                $addedBonus += (int)($src->total_bonus_credits ?? 0);
            }

            if ($addedCredits > 0 && in_array('available_credits', $targetCols)) {
                $targetUpdates['available_credits'] = (int)($targetUser->available_credits ?? 0) + $addedCredits;
            }
            if ($addedBonus > 0 && in_array('total_bonus_credits', $targetCols)) {
                $targetUpdates['total_bonus_credits'] = (int)($targetUser->total_bonus_credits ?? 0) + $addedBonus;
            }

            if (!empty($targetUpdates)) {
                $targetUpdates['updated_at'] = now();
                DB::table('users')->where('id', $targetId)->update($targetUpdates);
            }

            // 11. Deactivate & Invalidate Source Clients
            $now = now();
            foreach ($sourceUsers as $src) {
                $deactivatePayload = [
                    'status' => 'Merged',
                    'updated_at' => $now,
                ];
                if (in_array('token', $targetCols)) {
                    $deactivatePayload['token'] = null;
                }
                if (in_array('api_key', $targetCols)) {
                    $deactivatePayload['api_key'] = null;
                }
                if (in_array('deleted_at', $targetCols)) {
                    $deactivatePayload['deleted_at'] = $now;
                }
                DB::table('users')->where('id', $src->id)->update($deactivatePayload);
            }

            // Revoke Sanctum tokens if table exists
            if (Schema::hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', 'App\Models\User')
                    ->whereIn('tokenable_id', $sourceIds)
                    ->delete();
            }

            // Delete password reset OTPs
            if (Schema::hasTable('password_reset_otps')) {
                DB::table('password_reset_otps')->whereIn('user_id', $sourceIds)->delete();
            }

            // Sync pcb_users if table exists
            if (Schema::hasTable('pcb_users')) {
                DB::table('pcb_users')->whereIn('id', $sourceIds)->update(['status' => 'Merged', 'updated_at' => $now]);
            }

            // 12. Save Audit & Merge History Records
            $mergeId = DB::table('client_merges')->insertGetId([
                'target_user_id' => $targetId,
                'performed_by' => $adminId,
                'performed_by_name' => $adminName ?? 'Admin',
                'source_clients_count' => count($sourceIds),
                'orders_migrated' => $ordersMigrated,
                'payments_migrated' => $paymentsMigrated,
                'addresses_migrated' => $addressesMigrated,
                'gerbers_migrated' => $gerbersMigrated,
                'tickets_migrated' => $ticketsMigrated,
                'conflict_resolutions' => json_encode($conflictResolutions),
                'summary' => json_encode([
                    'source_ids' => $sourceIds,
                    'target_id' => $targetId,
                    'migrated_at' => $now->toIso8601String(),
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($sourceUsers as $src) {
                $srcStats = $this->getClientRecordStats($src->id);
                DB::table('client_merge_items')->insert([
                    'client_merge_id' => $mergeId,
                    'source_user_id' => $src->id,
                    'source_name' => $src->name ?? trim(($src->first_name ?? '') . ' ' . ($src->last_name ?? '')),
                    'source_email' => $src->email,
                    'source_company' => $src->company_name ?? null,
                    'migrated_stats' => json_encode($srcStats),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Log to system_maintenance_logs
            try {
                DB::table('system_maintenance_logs')->insert([
                    'admin_id' => $adminId,
                    'admin_name' => $adminName ?? 'Admin',
                    'action' => 'merge_clients',
                    'target_system' => 'clients',
                    'status' => 'success',
                    'started_at' => $now,
                    'completed_at' => $now,
                    'metadata' => json_encode([
                        'merge_id' => $mergeId,
                        'target_user_id' => $targetId,
                        'source_user_ids' => $sourceIds,
                        'orders_migrated' => $ordersMigrated,
                        'payments_migrated' => $paymentsMigrated,
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Throwable $e) {
                Log::warning("Failed to log client merge maintenance log: " . $e->getMessage());
            }

            return [
                'merge_id' => $mergeId,
                'target_id' => $targetId,
                'source_ids' => $sourceIds,
                'orders_migrated' => $ordersMigrated,
                'payments_migrated' => $paymentsMigrated,
                'addresses_migrated' => $addressesMigrated,
                'gerbers_migrated' => $gerbersMigrated,
                'tickets_migrated' => $ticketsMigrated,
                'stats' => [
                    'orders_migrated' => $ordersMigrated,
                    'payments_migrated' => $paymentsMigrated,
                    'addresses_migrated' => $addressesMigrated,
                    'gerbers_migrated' => $gerbersMigrated,
                    'tickets_migrated' => $ticketsMigrated,
                    'sources_deactivated' => count($sourceIds)
                ]
            ];
        });
    }

    /**
     * Helper to validate source and target participants.
     */
    private function validateMergeParticipants(array $sourceIds, int $targetId): void
    {
        if (empty($sourceIds)) {
            throw new Exception("At least one source client must be selected for merge.");
        }

        if (in_array($targetId, $sourceIds)) {
            throw new Exception("Target client cannot also be selected as a source client.");
        }

        $target = DB::table('users')->where('id', $targetId)->first();
        if (!$target) {
            throw new Exception("Target client record not found.");
        }

        $targetStatus = strtolower(trim((string)($target->status ?? '')));
        if ($targetStatus === 'deleted' || $targetStatus === 'merged' || (!empty($target->deleted_at))) {
            throw new Exception("Target client is inactive, merged, or deleted.");
        }

        $sources = DB::table('users')->whereIn('id', $sourceIds)->get();
        if (count($sources) !== count(array_unique($sourceIds))) {
            throw new Exception("One or more source client records could not be found.");
        }

        foreach ($sources as $src) {
            $srcStatus = strtolower(trim((string)($src->status ?? '')));
            if ($srcStatus === 'deleted' || $srcStatus === 'merged' || (!empty($src->deleted_at))) {
                throw new Exception("Source client '{$src->email}' is already inactive, merged, or deleted.");
            }
        }
    }

    /**
     * Helper to fetch record statistics for a client.
     */
    private function getClientRecordStats(int $userId): array
    {
        $ordersQuery = DB::table('pcb_orders')->where('user_id', $userId);
        $ordersCount = $ordersQuery->count();
        $totalSpent = (float)$ordersQuery->sum('order_value');

        $paymentsCount = DB::table('payment_transactions')->where('user_id', $userId)->count();
        $addressesCount = DB::table('user_addresses')->where('user_id', $userId)->count();
        $gerbersCount = DB::table('gerber_files')->where('user_id', $userId)->count();
        $ticketsCount = DB::table('support_tickets')->where('user_id', $userId)->count();

        return [
            'user_id' => $userId,
            'orders_count' => $ordersCount,
            'total_spent' => $totalSpent,
            'payments_count' => $paymentsCount,
            'addresses_count' => $addressesCount,
            'gerbers_count' => $gerbersCount,
            'tickets_count' => $ticketsCount,
        ];
    }

    /**
     * Helper to format User object for JSON payload.
     */
    private function formatUserObj($u): array
    {
        return [
            'id' => (int)$u->id,
            'name' => $u->name ?? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
            'first_name' => $u->first_name ?? '',
            'last_name' => $u->last_name ?? '',
            'email' => $u->email,
            'phone_number' => $u->phone_number ?? $u->mobile ?? $u->phone ?? '',
            'company_name' => $u->company_name ?? '',
            'status' => $u->status ?? 'Active',
            'country' => $u->country ?? 'India',
            'available_credits' => (int)($u->available_credits ?? 0),
            'created_at' => $u->created_at ?? null,
        ];
    }
}
