<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case COUNTER_CASH = 'COUNTER_CASH';
    case STUDENT_CARD = 'STUDENT_CARD';
    case STUDENT_CARD_POS = 'STUDENT_CARD_POS';
    case VNPAY = 'VNPAY';

    public function label(): string
    {
        return match ($this) {
            self::COUNTER_CASH => 'Thanh toán tại quầy',
            self::STUDENT_CARD => 'Thẻ học sinh (Mini App)',
            self::STUDENT_CARD_POS => 'Quẹt thẻ học sinh',
            self::VNPAY => 'Thanh toán VNPay (Sandbox)',
        };
    }
}
