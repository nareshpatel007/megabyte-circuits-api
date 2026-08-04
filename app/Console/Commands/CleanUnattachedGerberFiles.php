<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanUnattachedGerberFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gerber:clean-unattached';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove unattached gerber files older than 15 days from storage and database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $cutoff = now()->subDays(15);

        // Pick gerber files created more than 15 days ago where user_id is NULL
        $files = DB::table('gerber_files')
            ->whereNull('user_id')
            ->where('created_at', '<=', $cutoff)
            ->get();

        $count = 0;
        foreach ($files as $file) {
            // Delete zip/file from storage if file_path exists
            if (!empty($file->file_path) && Storage::disk('public')->exists($file->file_path)) {
                Storage::disk('public')->delete($file->file_path);
            }

            // Delete entry from DB
            DB::table('gerber_files')->where('id', $file->id)->delete();
            $count++;
        }

        $this->info("Cleaned up {$count} unattached gerber file(s) created before {$cutoff}.");

        return Command::SUCCESS;
    }
}
