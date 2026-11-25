<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBankBalanceException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        string $message = 'Tài khoản ngân hàng liên kết không đủ số dư để thực hiện giao dịch.',
        public readonly array $meta = [],
        public readonly string $errorCode = 'INSUFFICIENT_BANK_BALANCE',
    ) {
        parent::__construct($message);
    }
}
