<?php

namespace App\Modules\IdentityTenant;

use Illuminate\Mail\Mailable;

final class PasswordResetMail extends Mailable
{
    public function __construct(
        public readonly string $resetUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Reset hasła PrawkoNaRaz')
            ->text('mail.password-reset', [
                'resetUrl' => $this->resetUrl,
            ]);
    }
}
