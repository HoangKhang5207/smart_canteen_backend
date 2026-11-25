<?php

namespace App\Exceptions;

use RuntimeException;

class CardNotLinkedException extends RuntimeException
{
    public function __construct(
        string $message = 'Thẻ học sinh chưa liên kết tài khoản ngân hàng.',
        public readonly string $errorCode = 'CARD_NOT_BANK_LINKED',
    ) {
        parent::__construct($message);
    }
}
