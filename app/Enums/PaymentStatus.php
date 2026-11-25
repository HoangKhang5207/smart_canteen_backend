<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case UNPAID = 'UNPAID';
    case PAID = 'PAID';
    case REFUNDED = 'REFUNDED';

    public function label(): string
    {
        return match ($this) {
            self::UNPAID => 'Chờ thanh toán',
            self::PAID => 'Đã thanh toán',
            self::REFUNDED => 'Đã hoàn tiền',
        };
    }
}
