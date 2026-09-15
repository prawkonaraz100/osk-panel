<?php

namespace App\Modules\UploadsAssets;

use RuntimeException;

final class UploadRejectedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $assetId,
        public readonly string $machineCode = 'VALIDATION_FAILED',
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
