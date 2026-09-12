<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class InternalExamAccessLinkMail extends Mailable
{
    public function __construct(
        public readonly string $candidateName,
        public readonly string $examUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Link do egzaminu wewnętrznego',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.internal-exam-access-link',
        );
    }

    /** @return array<int, \Illuminate\Mail\Mailables\Attachment> */
    public function attachments(): array
    {
        return [];
    }
}
