<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessGerberAnalysis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gerber:process {id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process Gerber PCB analysis asynchronously for a gerber file ID';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $id = $this->argument('id');
        $fileRecord = DB::table('gerber_files')->where('id', $id)->first();

        if (!$fileRecord) {
            $this->error("Gerber file record with ID {$id} not found.");
            return Command::FAILURE;
        }

        if ($fileRecord->status === 'completed') {
            $this->info("Gerber file ID {$id} is already completed.");
            return Command::SUCCESS;
        }

        // Get full local path to stored file
        $filePath = storage_path('app/public/' . $fileRecord->file_path);

        if (!file_exists($filePath)) {
            $this->error("File not found on disk: {$filePath}");
            DB::table('gerber_files')->where('id', $id)->update([
                'status' => 'failed',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            return Command::FAILURE;
        }

        $pythonUrl = config('services.python_gerber.url', env('PYTHON_GERBER_API_URL', 'http://127.0.0.1:8000'));
        $fileData = file_get_contents($filePath);
        $fileNameToUpload = $fileRecord->original_name ?: basename($filePath);

        $pythonResponse = null;
        try {
            $response = Http::timeout(180)
                ->attach('file', $fileData, $fileNameToUpload)
                ->post(rtrim($pythonUrl, '/') . '/api/analyze');

            if ($response->successful()) {
                $pythonResponse = $response->json();
            } else {
                logger()->error("ProcessGerberAnalysis failed HTTP " . $response->status() . " for ID {$id}: " . $response->body());
            }
        } catch (\Exception $ex) {
            logger()->error("ProcessGerberAnalysis exception for ID {$id}: " . $ex->getMessage());
        }

        if (!$pythonResponse || empty($pythonResponse['project_id'])) {
            DB::table('gerber_files')->where('id', $id)->update([
                'status' => 'failed',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            $this->error("Python Gerber analysis failed for ID {$id}");
            return Command::FAILURE;
        }

        // Extract Python analysis results
        $pythonProjectId = $pythonResponse['project_id'];
        $boardWidth = $pythonResponse['board_width'] ?? ($pythonResponse['board_size']['width_mm'] ?? null);
        $boardHeight = $pythonResponse['board_height'] ?? ($pythonResponse['board_size']['height_mm'] ?? null);
        $layerCount = $pythonResponse['layer_count'] ?? 0;

        $previewFrontRel = $pythonResponse['preview_front'] ?? ($pythonResponse['pcb_previews']['preview_top_2d'] ?? null);
        $previewBackRel = $pythonResponse['preview_back'] ?? ($pythonResponse['pcb_previews']['preview_bottom_2d'] ?? null);

        if (!$previewFrontRel && $pythonProjectId) {
            $previewFrontRel = "/projects/{$pythonProjectId}/renders/pcb_top_2d.png";
        }
        if (!$previewBackRel && $pythonProjectId) {
            $previewBackRel = "/projects/{$pythonProjectId}/renders/pcb_bottom_2d.png";
        }

        $frontPreviewUrl = "/api/gerber/{$id}/preview/front";
        $backPreviewUrl = "/api/gerber/{$id}/preview/back";

        // Upload Gerber archive to JLCPCB Open API
        $jlcpcbFileKey = null;
        $jlcpcbUploadStatus = 'pending';
        $jlcpcbUploadError = null;
        $jlcpcbUploadedAt = null;

        try {
            /** @var \App\Services\JlcpcbService $jlcService */
            $jlcService = app(\App\Services\JlcpcbService::class);
            $jlcResult = $jlcService->uploadGerber($filePath, $fileNameToUpload);

            $clientIp = $jlcResult['request_client_ip'] ?? $jlcService->getRequestClientIp();
            $outboundIp = $jlcResult['outbound_ip'] ?? $jlcService->getOutboundPublicIp();
            $ipStr = "Request Client IP: {$clientIp}" . ($outboundIp ? " | Outbound Public IP: {$outboundIp}" : "");

            if (!empty($jlcResult['success']) && !empty($jlcResult['fileKey'])) {
                $jlcpcbFileKey = $jlcResult['fileKey'];
                $jlcpcbUploadStatus = 'completed';
                $jlcpcbUploadedAt = date('Y-m-d H:i:s');
                $this->info("JLCPCB upload successful for Gerber ID {$id} [{$ipStr}]. FileKey: {$jlcpcbFileKey}");
                logger()->info("JLCPCB upload successful for Gerber ID {$id} [{$ipStr}]. FileKey: {$jlcpcbFileKey}");
            } else {
                $jlcpcbUploadStatus = 'failed';
                $jlcpcbUploadError = $jlcResult['message'] ?? 'JLCPCB upload returned failure response';
                logger()->warning("JLCPCB upload failed for Gerber ID {$id} [{$ipStr}]: " . $jlcpcbUploadError, [
                    'request_client_ip' => $clientIp,
                    'outbound_ip' => $outboundIp
                ]);
            }
        } catch (\Exception $ex) {
            $jlcpcbUploadStatus = 'failed';
            $jlcpcbUploadError = $ex->getMessage();
            $ipStr = isset($jlcService) ? $jlcService->getLogIpString() : 'Request Client IP: Unknown';
            logger()->error("JLCPCB upload exception for Gerber ID {$id} [{$ipStr}]: " . $ex->getMessage());
        }

        DB::table('gerber_files')->where('id', $id)->update([
            'status' => 'completed',
            'python_project_id' => $pythonProjectId,
            'board_width' => $boardWidth,
            'board_height' => $boardHeight,
            'layer_count' => $layerCount,
            'preview_data' => $frontPreviewUrl,
            'front_preview_url' => $previewFrontRel,
            'back_preview_url' => $previewBackRel,
            'analysis_data' => json_encode($pythonResponse),
            'jlcpcb_file_key' => $jlcpcbFileKey,
            'jlcpcb_upload_status' => $jlcpcbUploadStatus,
            'jlcpcb_uploaded_at' => $jlcpcbUploadedAt,
            'jlcpcb_upload_error' => $jlcpcbUploadError,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $this->info("Gerber file ID {$id} successfully processed.");
        return Command::SUCCESS;
    }
}
