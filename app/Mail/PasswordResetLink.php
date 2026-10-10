<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The link that lets somebody set a new password.
 *
 * The subject is the live script's, word for word, because people search
 * their inbox for it.
 */
class PasswordResetLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $link,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '12 Step Toolkit App – Password Reset Link');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset', with: [
            'link' => $this->link,
            'minutes' => $this->minutes,
        ]);
    }
}
