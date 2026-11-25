<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public string $errorCode = 'INSUFFICIENT_STOCK';

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        string $message,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }
}
