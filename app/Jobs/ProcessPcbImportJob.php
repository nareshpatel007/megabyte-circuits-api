<?php

namespace App\Jobs;

use App\Models\PcbImport;
use App\Services\OrderImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessPcbImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $importId;

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(int $importId)
    {
        $this->importId = $importId;
    }

    /**
     * Execute the job.
     */
    public function handle(OrderImportService $importService): void
    {
        $import = PcbImport::find($this->importId);

        if (!$import || $import->status === 'cancelled') {
            Log::info("ProcessPcbImportJob: Import #{$this->importId} missing or cancelled.");
            return;
        }

        try {
            $importService->processBackgroundImport($import);
        } catch (\Throwable $th) {
            Log::error("ProcessPcbImportJob failed for import #{$this->importId}: " . $th->getMessage(), [
                'exception' => $th
            ]);

            $import->fresh();
            $import->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $th->getMessage(),
            ]);
        } finally {
            // Delete temporary file after processing or permanent failure
            $import->refresh();
            if ($import->file_path) {
                if (Storage::disk('local')->exists($import->file_path)) {
                    Storage::disk('local')->delete($import->file_path);
                } elseif (file_exists($import->file_path)) {
                    @unlink($import->file_path);
                }
            }
        }
    }
}
