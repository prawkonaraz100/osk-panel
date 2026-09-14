<?php

namespace App\Modules\IdentityTenant;

use RuntimeException;

final class SocialAuthFlowException extends RuntimeException
{
    public function __construct(
        public readonly string $returnUrl = '/',
    ) {
        parent::__construct('Social authentication could not be completed.');
    }
}
