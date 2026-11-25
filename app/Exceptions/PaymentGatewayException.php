<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'PAYMENT_FAILED',
        public readonly int $statusCode = 502,
        public readonly string $transactionStatus = 'FAILED',
    ) {
        parent::__construct($message);
    }
}
