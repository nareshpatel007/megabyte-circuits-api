<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Models\EmailTemplate;
use App\Models\EmailLog;
use App\Services\EmailTemplateService;
use App\Services\CredentialService;
use Carbon\Carbon;

class SendInventoryTemplateEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [10, 60, 300];

    protected string $templateKey;
    protected int $inventoryItemId;
    protected ?int $logId;
    protected ?string $overrideTo;
    protected array $customVars;

    /**
     * Create a new job instance.
     */
    public function __construct(string $templateKey, int $inventoryItemId, ?int $logId = null, ?string $overrideTo = null, array $customVars = [])
    {
        $this->templateKey = $templateKey;
        $this->inventoryItemId = $inventoryItemId;
        $this->logId = $logId;
        $this->overrideTo = $overrideTo;
        $this->customVars = $customVars;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $item = InventoryItem::find($this->inventoryItemId);

        if (!$item) {
            Log::warning("SendInventoryTemplateEmailJob: Inventory item #{$this->inventoryItemId} not found for template '{$this->templateKey}'.");
            return;
        }

        $log = $this->logId ? InventoryLog::find($this->logId) : null;

        $rendered = EmailTemplateService::renderInventory($this->templateKey, $item, $log, $this->customVars);

        $toEmail   = $this->overrideTo ?: ($rendered['to'] ?? null);
        $fromEmail = $rendered['from_email'] ?? CredentialService::get('mail', 'MAIL_GLOBAL_FROM_ADDRESS', 'MAIL_GLOBAL_FROM_ADDRESS', config('mail.from.address'));
        $fromName  = $rendered['from_name'] ?? CredentialService::get('mail', 'MAIL_GLOBAL_FROM_NAME', 'MAIL_GLOBAL_FROM_NAME', config('mail.from.name'));

        // 1. Check if template exists & active
        if (!$rendered['success'] || !($rendered['is_active'] ?? false)) {
            $skippedMsg = $rendered['message'] ?? "Email skipped: template '{$this->templateKey}' is inactive.";
            Log::info("SendInventoryTemplateEmailJob: {$skippedMsg}");
            EmailLog::create([
                'template_key'      => $this->templateKey,
                'inventory_item_id' => $this->inventoryItemId,
                'from_email'        => $fromEmail,
                'from_name'         => $fromName,
                'to'                => $toEmail ?? 'N/A',
                'cc'                => implode(', ', $rendered['cc'] ?? []),
                'bcc'               => implode(', ', $rendered['bcc'] ?? []),
                'subject'           => $rendered['subject'] ?? 'Inventory Alert',
                'status'            => 'skipped',
                'error_message'     => $skippedMsg,
            ]);
            return;
        }

        // 2. Validate recipient email
        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            EmailLog::create([
                'template_key'      => $this->templateKey,
                'inventory_item_id' => $this->inventoryItemId,
                'from_email'        => $fromEmail,
                'from_name'         => $fromName,
                'to'                => $toEmail ?? 'N/A',
                'cc'                => implode(', ', $rendered['cc'] ?? []),
                'bcc'               => implode(', ', $rendered['bcc'] ?? []),
                'subject'           => $rendered['subject'],
                'status'            => 'skipped',
                'error_message'     => 'No valid recipient email address configured for inventory notifications',
            ]);
            return;
        }

        // 3. Configure global SMTP mailer dynamically via CredentialService
        $mailer = 'smtp_global';
        Config::set('mail.mailers.smtp_global.host', CredentialService::get('mail', 'MAIL_GLOBAL_HOST', 'MAIL_GLOBAL_HOST', config('mail.mailers.smtp.host')));
        Config::set('mail.mailers.smtp_global.port', CredentialService::get('mail', 'MAIL_GLOBAL_PORT', 'MAIL_GLOBAL_PORT', config('mail.mailers.smtp.port', 587)));
        Config::set('mail.mailers.smtp_global.username', CredentialService::get('mail', 'MAIL_GLOBAL_USERNAME', 'MAIL_GLOBAL_USERNAME', config('mail.mailers.smtp.username')));
        Config::set('mail.mailers.smtp_global.password', CredentialService::get('mail', 'MAIL_GLOBAL_PASSWORD', 'MAIL_GLOBAL_PASSWORD', config('mail.mailers.smtp.password')));
        Config::set('mail.from.address', $fromEmail);
        Config::set('mail.from.name', $fromName);

        try {
            Mail::mailer($mailer)->send([], [], function ($message) use ($toEmail, $fromEmail, $fromName, $rendered) {
                $message->to($toEmail)
                    ->from($fromEmail, $fromName)
                    ->subject($rendered['subject'])
                    ->html($rendered['body']);

                if (!empty($rendered['cc'])) {
                    $message->cc($rendered['cc']);
                }

                if (!empty($rendered['bcc'])) {
                    $message->bcc($rendered['bcc']);
                }
            });

            EmailLog::create([
                'template_key'      => $this->templateKey,
                'inventory_item_id' => $this->inventoryItemId,
                'from_email'        => $fromEmail,
                'from_name'         => $fromName,
                'to'                => $toEmail,
                'cc'                => implode(', ', $rendered['cc']),
                'bcc'               => implode(', ', $rendered['bcc']),
                'subject'           => $rendered['subject'],
                'status'            => 'sent',
                'sent_at'           => Carbon::now(),
            ]);

            Log::info("SendInventoryTemplateEmailJob: Email '{$this->templateKey}' successfully sent to '{$toEmail}' from '{$fromName} <{$fromEmail}>' for Inventory Item #{$this->inventoryItemId}.");
        } catch (\Throwable $th) {
            EmailLog::create([
                'template_key'      => $this->templateKey,
                'inventory_item_id' => $this->inventoryItemId,
                'from_email'        => $fromEmail,
                'from_name'         => $fromName,
                'to'                => $toEmail,
                'cc'                => implode(', ', $rendered['cc']),
                'bcc'               => implode(', ', $rendered['bcc']),
                'subject'           => $rendered['subject'],
                'status'            => 'failed',
                'error_message'     => $th->getMessage(),
            ]);

            Log::error("SendInventoryTemplateEmailJob: Email '{$this->templateKey}' failed to send to '{$toEmail}': " . $th->getMessage());

            throw $th;
        }
    }
}
