<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ClientMergeService;

class ClientMergeController extends Controller
{
    protected ClientMergeService $mergeService;

    public function __construct(ClientMergeService $mergeService)
    {
        $this->mergeService = $mergeService;
    }

    /**
     * Search clients eligible for merge.
     */
    public function search(Request $request)
    {
        try {
            $query = $request->input('q') ?: $request->input('search');
            $clients = $this->mergeService->searchClients($query);

            return response()->json([
                'success' => true,
                'status' => true,
                'data' => $clients,
                'clients' => $clients
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'status' => false,
                'message' => 'Failed to search clients',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Preview impact and conflicts of merging source clients into target client.
     */
    public function preview(Request $request)
    {
        try {
            $sourceIds = $request->input('source_client_ids', []);
            $targetId = (int)$request->input('target_client_id');

            if (empty($sourceIds) || !is_array($sourceIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please select at least one source client.'
                ], 422);
            }

            if (empty($targetId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please select a target client.'
                ], 422);
            }

            $preview = $this->mergeService->getMergePreview(array_map('intval', $sourceIds), $targetId);

            return response()->json([
                'success' => true,
                'status' => true,
                'data' => $preview
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'status' => false,
                'message' => $e->getMessage() ?: 'Failed to calculate merge preview',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Execute transactional client merge.
     */
    public function execute(Request $request)
    {
        try {
            $sourceIds = $request->input('source_client_ids', []);
            $targetId = (int)$request->input('target_client_id');
            $conflictResolutions = $request->input('conflict_resolutions', []);
            $confirmation = (string)$request->input('confirmation', '');

            if (empty($sourceIds) || !is_array($sourceIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please select at least one source client.'
                ], 422);
            }

            if (empty($targetId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please select a target client.'
                ], 422);
            }

            if (strtoupper(trim($confirmation)) !== 'MERGE') {
                return response()->json([
                    'success' => false,
                    'message' => 'Please type MERGE in all uppercase to confirm execution.'
                ], 422);
            }

            $adminId = $request->attributes->get('admin_id') ?: ($request->user()?->id);
            $adminName = $request->attributes->get('admin_name') ?: ($request->user()?->name);

            $result = $this->mergeService->executeMerge(
                array_map('intval', $sourceIds),
                $targetId,
                is_array($conflictResolutions) ? $conflictResolutions : [],
                $confirmation,
                $adminId,
                $adminName
            );

            return response()->json([
                'success' => true,
                'status' => true,
                'message' => 'Client accounts merged successfully!',
                'data' => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'status' => false,
                'message' => $e->getMessage() ?: 'Client merge failed.',
                'error' => $e->getMessage()
            ], 400);
        }
    }
}
