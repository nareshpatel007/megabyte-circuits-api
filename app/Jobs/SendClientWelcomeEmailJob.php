<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\EmailLog;
use App\Services\RegisterService;

class SendClientWelcomeEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [10, 60, 300];

    protected int $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $user = User::find($this->userId);

        if (!$user) {
            Log::warning("SendClientWelcomeEmailJob: User #{$this->userId} not found.");
            return;
        }

        // Idempotency check: don't send welcome email multiple times to same user
        $alreadySent = EmailLog::where('customer_id', $this->userId)
            ->where('template_key', 'client_welcome')
            ->where('status', 'sent')
            ->exists();

        if ($alreadySent) {
            Log::info("SendClientWelcomeEmailJob: Welcome email already sent for user #{$this->userId}. Skipping duplicate.");
            return;
        }

        $name = $user->name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        if (empty($name)) {
            $name = strtok($user->email, '@');
        }

        RegisterService::sendWelcomeEmail($user->id, $name, $user->email);
    }
}
