<?php

namespace App\Services;

use App\Models\Status;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OrderStatusResolver
{
    /**
     * Memory cache of statuses for the lifecycle of the current request.
     * @var Collection|null
     */
    protected static ?Collection $statusCache = null;

    /**
     * Non-production terminal / inactive statuses.
     */
    public const NON_PRODUCTION_STATUSES = [
        'pending',
        'completed',
        'shipped',
        'delivered',
        'cancelled',
        'canceled',
    ];

    /**
     * Clear cached statuses (useful during testing or after status updates).
     */
    public static function clearCache(): void
    {
        static::$statusCache = null;
    }

    /**
     * Get all active statuses from normalized table.
     */
    public static function getAllStatuses(): Collection
    {
        if (static::$statusCache !== null) {
            return static::$statusCache;
        }

        if (!Schema::hasTable('pcb_order_statuses')) {
            return collect();
        }

        static::$statusCache = Status::orderBy('sort_order', 'asc')->get();
        return static::$statusCache;
    }

    /**
     * Resolve a status record by ID, name, label, or slug.
     *
     * @param mixed $statusOrId
     * @return Status|null
     */
    public static function resolve($statusOrId): ?Status
    {
        if ($statusOrId === null) {
            return null;
        }

        if ($statusOrId instanceof Status) {
            return $statusOrId;
        }

        $all = static::getAllStatuses();
        if ($all->isEmpty()) {
            return null;
        }

        // If numeric ID
        if (is_int($statusOrId) || (is_string($statusOrId) && ctype_digit(trim($statusOrId)))) {
            $id = (int) trim((string)$statusOrId);
            $found = $all->firstWhere('id', $id);
            if ($found) {
                return $found;
            }
        }

        $query = strtolower(trim((string)$statusOrId));
        if ($query === '') {
            return null;
        }

        // Handle legacy or common alias synonyms
        if ($query === 'move') {
            $query = 'completed';
        } elseif ($query === 'canceled') {
            $query = 'cancelled';
        }

        // 1. Exact match on name
        $matched = $all->first(function ($st) use ($query) {
            return strtolower(trim((string)$st->name)) === $query;
        });
        if ($matched) {
            return $matched;
        }

        // 2. Exact match on slug
        $matched = $all->first(function ($st) use ($query) {
            return !empty($st->slug) && strtolower(trim((string)$st->slug)) === $query;
        });
        if ($matched) {
            return $matched;
        }

        // 3. Exact match on label
        $matched = $all->first(function ($st) use ($query) {
            return !empty($st->label) && strtolower(trim((string)$st->label)) === $query;
        });
        if ($matched) {
            return $matched;
        }

        // 4. Normalized slug comparison (e.g. "ready-to-ship" vs "Ready to ship")
        $slugified = Str::slug($query);
        $matched = $all->first(function ($st) use ($slugified) {
            return Str::slug((string)$st->name) === $slugified ||
                   (!empty($st->slug) && Str::slug((string)$st->slug) === $slugified);
        });

        return $matched ?: null;
    }

    /**
     * Get the default initial status for new orders (Pending).
     */
    public static function getDefaultStatus(): ?Status
    {
        $all = static::getAllStatuses();
        if ($all->isEmpty()) {
            return null;
        }

        // Look for 'Pending'
        $pending = static::resolve('Pending');
        if ($pending) {
            return $pending;
        }

        // Fallback to first status
        return $all->first();
    }

    /**
     * Resolve status ensuring both name and ID refer to the same record if both provided.
     *
     * @param mixed $status
     * @param mixed $statusId
     * @return array [Status|null $record, string|null $errorMessage]
     */
    public static function resolveAndVerify($status, $statusId): array
    {
        $hasStatus = $status !== null && trim((string)$status) !== '';
        $hasId = $statusId !== null && trim((string)$statusId) !== '';

        if (!$hasStatus && !$hasId) {
            return [static::getDefaultStatus(), null];
        }

        $byName = $hasStatus ? static::resolve($status) : null;
        $byId = $hasId ? static::resolve($statusId) : null;

        if ($hasStatus && !$byName) {
            return [null, "The order status '" . trim((string)$status) . "' is invalid."];
        }

        if ($hasId && !$byId) {
            return [null, "The order status ID '" . trim((string)$statusId) . "' is invalid."];
        }

        if ($hasStatus && $hasId) {
            if ($byName->id !== $byId->id) {
                return [null, "The provided status ('{$byName->name}') and status_id ({$byId->id} - '{$byId->name}') do not refer to the same order status."];
            }
            return [$byName, null];
        }

        return [$byName ?: $byId, null];
    }

    /**
     * Determine if a status is considered "In Production".
     *
     * @param mixed $statusOrId
     * @return bool
     */
    public static function isInProduction($statusOrId): bool
    {
        $st = static::resolve($statusOrId);
        if (!$st) {
            $name = strtolower(trim((string)$statusOrId));
            return !in_array($name, static::NON_PRODUCTION_STATUSES, true) && $name !== '';
        }

        $name = strtolower(trim((string)$st->name));
        $slug = strtolower(trim((string)($st->slug ?? '')));

        return !in_array($name, static::NON_PRODUCTION_STATUSES, true)
            && !in_array($slug, static::NON_PRODUCTION_STATUSES, true);
    }
}
