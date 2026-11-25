<?php

namespace App\Exceptions;

use RuntimeException;

class SmsDeliveryException extends RuntimeException
{
    public function __construct(
        string $message = 'Không thể gửi tin nhắn OTP lúc này. Vui lòng thử lại sau.',
        public readonly string $errorCode = 'SMS_DELIVERY_FAILED',
        public readonly int $statusCode = 503,
    ) {
        parent::__construct($message);
    }
}
