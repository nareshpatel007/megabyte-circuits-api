<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class CrmEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $subject;
    public $body;
    public $inReplyTo;
    public $references;
    public $sentMessageId;

    /**
     * Create a new message instance.
     */
    public function __construct($subject, $body, $inReplyTo = null, $references = null)
    {
        $this->subject = $subject;
        $this->body = $body;
        $this->inReplyTo = $inReplyTo;
        $this->references = $references;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->view('emails.zoho.bulk_email')
            ->subject($this->subject)
            ->with(['body' => $this->body]);
    }
}
