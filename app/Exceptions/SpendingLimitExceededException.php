<?php

namespace App\Exceptions;

use RuntimeException;

class SpendingLimitExceededException extends RuntimeException
{
    /**
     * @param  array<string, int>  $meta
     */
    public function __construct(
        string $message,
        public readonly array $meta = [],
        public readonly string $errorCode = 'SPENDING_LIMIT_EXCEEDED',
    ) {
        parent::__construct($message);
    }
}
