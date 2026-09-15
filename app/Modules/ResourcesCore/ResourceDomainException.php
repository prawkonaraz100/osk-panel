<?php

namespace App\Modules\ResourcesCore;

use RuntimeException;
use Throwable;

final class ResourceDomainException extends RuntimeException
{
    public function __construct(
        public readonly string $machineCode,
        public readonly int $httpStatus,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self('RESOURCE_NOT_FOUND', 404, $message);
    }

    public static function conflict(string $message = 'Resource state conflict.'): self
    {
        return new self('RESOURCE_VERSION_CONFLICT', 409, $message);
    }

    public static function rule(string $message): self
    {
        return new self('VALIDATION_FAILED', 422, $message);
    }
}
