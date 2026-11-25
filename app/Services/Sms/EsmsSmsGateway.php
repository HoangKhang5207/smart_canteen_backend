<?php

namespace App\Services\Sms;

use App\Contracts\SmsGatewayInterface;
use App\Enums\OtpPurpose;
use App\Exceptions\SmsDeliveryException;
use App\Services\DataMaskingService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class EsmsSmsGateway implements SmsGatewayInterface
{
    public function __construct(
        private readonly PhoneNormalizer $phoneNormalizer,
        private readonly DataMaskingService $masking,
        private readonly OtpMessageFormatter $formatter,
    ) {}

    public function sendOtp(
        string $phoneNumber,
        string $otpCode,
        OtpPurpose $purpose,
        int $ttlSeconds,
    ): array {
        $apiKey = trim((string) config('services.sms.esms.api_key', ''));
        $secretKey = trim((string) config('services.sms.esms.secret_key', ''));
        $endpoint = trim((string) config('services.sms.esms.endpoint', ''));
        $smsType = (int) config('services.sms.esms.sms_type', 2);
        $sandbox = (bool) config('services.sms.esms.sandbox', true);
        $brandname = trim((string) config('services.sms.brandname', 'SmartCanteen'));

        if ($apiKey === '' || $secretKey === '' || $endpoint === '') {
            throw new SmsDeliveryException('Cổng gửi SMS (eSMS) chưa được cấu hình đầy đủ.');
        }

        $normalizedPhone = $this->phoneNormalizer->normalizeVietnamMobile($phoneNumber) ?? trim($phoneNumber);
        $content = $this->formatter->format($otpCode, $purpose, $ttlSeconds);

        $body = [
            'ApiKey' => $apiKey,
            'SecretKey' => $secretKey,
            'Phone' => $normalizedPhone,
            'Content' => $content,
            'SmsType' => (string) $smsType,
            'Sandbox' => $sandbox ? '1' : '0',
        ];

        if ($smsType === 2 && $brandname !== '') {
            $body['Brandname'] = $brandname;
        }

        try {
            $response = Http::timeout(8)
                ->retry(
                    2,
                    200,
                    fn (Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->acceptJson()
                ->post($endpoint, $body);
        } catch (Throwable $e) {
            Log::warning('eSMS OTP delivery network failure', [
                'phone_masked' => $this->masking->maskPhoneNumber($normalizedPhone),
                'purpose' => $purpose->value,
                'error' => $e->getMessage(),
            ]);

            throw new SmsDeliveryException;
        }

        $payload = $response->json();
        $codeResult = is_array($payload) ? (string) ($payload['CodeResult'] ?? '') : '';

        if (! $response->successful() || $codeResult !== '100') {
            Log::warning('eSMS OTP delivery rejected by provider', [
                'phone_masked' => $this->masking->maskPhoneNumber($normalizedPhone),
                'purpose' => $purpose->value,
                'http_status' => $response->status(),
                'code_result' => $codeResult,
            ]);

            throw new SmsDeliveryException;
        }

        $reference = is_array($payload) && ! empty($payload['SMSID'])
            ? (string) $payload['SMSID']
            : 'ESMS-'.Str::upper(Str::random(10));

        return [
            'provider' => 'esms',
            'status' => $sandbox ? 'SIMULATED' : 'SENT',
            'reference' => $reference,
        ];
    }
}
