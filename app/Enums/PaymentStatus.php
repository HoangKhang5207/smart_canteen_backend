<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case UNPAID = 'UNPAID';
    case PAID = 'PAID';
    case REFUNDED = 'REFUNDED';
    case FAILED = 'FAILED';
    case PENDING_RECONCILE = 'PENDING_RECONCILE';

    public function label(): string
    {
        return match ($this) {
            self::UNPAID => 'Chờ thanh toán',
            self::PAID => 'Đã thanh toán',
            self::REFUNDED => 'Đã hoàn tiền',
            self::FAILED => 'Thanh toán thất bại',
            self::PENDING_RECONCILE => 'Chờ đối soát',
        };
    }
}
