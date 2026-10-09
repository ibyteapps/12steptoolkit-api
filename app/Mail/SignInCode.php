<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The four-digit code that signs somebody in to /my.
 *
 * A class rather than `Mail::send()` with a closure so that a test can assert
 * it was sent, to whom, and with which code — which is how the sign-in tests
 * read the code without the application ever logging it.
 */
class SignInCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your 12 Step Toolkit sign-in code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.sign-in-code', with: ['code' => $this->code]);
    }
}
