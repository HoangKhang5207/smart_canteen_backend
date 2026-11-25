<?php

namespace App\Services\Sms;

use App\Enums\OtpPurpose;

class OtpMessageFormatter
{
    public function format(string $otpCode, OtpPurpose $purpose, int $ttlSeconds): string
    {
        $minutes = max(1, (int) ceil($ttlSeconds / 60));

        return match ($purpose) {
            OtpPurpose::BANK_LINK => sprintf(
                'Ma OTP lien ket ngan hang Smart Canteen cua ban la %s. Hieu luc %d phut. Khong chia se ma nay.',
                $otpCode,
                $minutes,
            ),
            OtpPurpose::BANK_UNLINK => sprintf(
                'Ma OTP huy lien ket ngan hang Smart Canteen cua ban la %s. Hieu luc %d phut. Khong chia se ma nay.',
                $otpCode,
                $minutes,
            ),
        };
    }
}
