<?php

namespace App\Exceptions;

use RuntimeException;

class BankLinkConflictException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'BANK_LINK_CONFLICT',
    ) {
        parent::__construct($message);
    }
}
