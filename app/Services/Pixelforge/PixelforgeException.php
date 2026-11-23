<?php

namespace App\Services\Pixelforge;

use RuntimeException;

class PixelforgeException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly array $body = [],
    ) {
        parent::__construct($message, $status);
    }

    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }

    public function isDailyLimit(): bool
    {
        return $this->errorCode === 'daily_limit_exceeded';
    }
}
