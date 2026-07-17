<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SendMail extends Mailable
{
    use Queueable, SerializesModels;

    public $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function build()
    {
        // Send email
        $email = $this
            ->subject($this->data['subject'])
            ->view($this->data['template'])
            ->with('data', $this->data);

        // Add CC
        if (!empty($this->data['cc'])) {
            $email->cc($this->data['cc']);
        }

        // Attach files if exist
        if (!empty($this->data['attachments']) && is_array($this->data['attachments'])) {
            foreach ($this->data['attachments'] as $url) {
                $email->attach($url);
            }
        }

        // Return response
        return $email;
    }
}