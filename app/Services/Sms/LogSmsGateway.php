<?php

namespace App\Services\Sms;

use App\Contracts\SmsGatewayInterface;
use App\Enums\OtpPurpose;
use App\Services\DataMaskingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogSmsGateway implements SmsGatewayInterface
{
    public function __construct(
        private readonly DataMaskingService $masking,
    ) {}

    public function sendOtp(
        string $phoneNumber,
        string $otpCode,
        OtpPurpose $purpose,
        int $ttlSeconds,
    ): array {
        $reference = 'LOG-SMS-'.Str::upper(Str::random(10));

        // Never log plaintext phone numbers or plaintext OTP codes.
        Log::info('SMS OTP dispatched (log driver)', [
            'provider' => 'log',
            'status' => 'SIMULATED',
            'reference' => $reference,
            'purpose' => $purpose->value,
            'phone_masked' => $this->masking->maskPhoneNumber($phoneNumber),
            'ttl_seconds' => $ttlSeconds,
        ]);

        return [
            'provider' => 'log',
            'status' => 'SIMULATED',
            'reference' => $reference,
        ];
    }
}
