<?php

namespace App\Console\Commands;

use Webklex\IMAP\Facades\Client;
use Illuminate\Console\Command;
use App\Models\EmailMessage;
use App\Models\EmailThread;

class FetchZohoEmails extends Command
{
    protected $signature = 'zoho:fetch-emails';
    protected $description = 'Fetch new emails from Zoho IMAP inbox';

    public function handle()
    {
        $client = Client::account('default');
        $client->connect();

        $inbox = $client->getFolder('INBOX');
        $messages = $inbox->query()->unseen()->get();

        foreach ($messages as $mail) {
            $msgId = trim($mail->getMessageId());
            $inReplyTo = trim($mail->getHeader('in-reply-to'));
            $references = trim($mail->getHeader('references'));
            $subject = $mail->getSubject();
            $from = $mail->getFrom()[0]->mail;
            $to = implode(',', array_map(fn($a) => $a->mail, $mail->getTo()));
            $body = $mail->getHTMLBody() ?? $mail->getTextBody();

            // check if exists
            if (EmailMessage::where('message_id', $msgId)->exists()) continue;

            // find thread
            $threadId = null;
            if ($inReplyTo) {
                $parent = EmailMessage::where('message_id', $inReplyTo)->first();
                if ($parent) $threadId = $parent->thread_id;
            }
            if (!$threadId && $references) {
                $refIds = preg_split('/\s+/', $references);
                foreach ($refIds as $ref) {
                    $refMsg = EmailMessage::where('message_id', trim($ref))->first();
                    if ($refMsg) { $threadId = $refMsg->thread_id; break; }
                }
            }
            if (!$threadId) {
                $thread = EmailThread::create(['subject' => $subject]);
                $threadId = $thread->id;
            }

            EmailMessage::create([
                'thread_id' => $threadId,
                'message_id' => $msgId,
                'in_reply_to' => $inReplyTo,
                'references' => $references,
                'from' => $from,
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
                'is_inbound' => true,
            ]);

            $mail->setFlag('Seen');
        }

        $this->info('Zoho emails fetched successfully.');
    }
}