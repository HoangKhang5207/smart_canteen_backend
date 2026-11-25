<?php

namespace App\Exceptions;

use RuntimeException;

class ZaloAuthException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 401,
        public readonly string $errorCode = 'ZALO_AUTH_FAILED',
    ) {
        parent::__construct($message);
    }
}
