<?php

namespace App\Contracts;

use App\Enums\OtpPurpose;
use App\Exceptions\SmsDeliveryException;

interface SmsGatewayInterface
{
    /**
     * Dispatch a 6-digit OTP message to the recipient's phone number.
     *
     * @return array{
     *     provider: string,
     *     status: string,
     *     reference: string
     * }
     *
     * @throws SmsDeliveryException
     */
    public function sendOtp(
        string $phoneNumber,
        string $otpCode,
        OtpPurpose $purpose,
        int $ttlSeconds,
    ): array;
}
