<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class ProductionPagingSmokeMail extends Mailable
{
    public function __construct(
        public readonly string $smokeId,
        public readonly string $generatedAt,
        public readonly string $policyVersion,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[OSK Panel] Production paging smoke test',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.production-paging-smoke',
        );
    }
}
