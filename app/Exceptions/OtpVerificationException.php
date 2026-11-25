<?php

namespace App\Exceptions;

use RuntimeException;

class OtpVerificationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $statusCode = 422,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }
}
