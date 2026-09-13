<?php

namespace App\Modules\UploadsAssets;

use RuntimeException;

final class UploadRejectedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $assetId,
    ) {
        parent::__construct($message);
    }
}
