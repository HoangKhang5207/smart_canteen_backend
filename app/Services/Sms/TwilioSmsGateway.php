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

class TwilioSmsGateway implements SmsGatewayInterface
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
        $accountSid = trim((string) config('services.sms.twilio.account_sid', ''));
        $authToken = trim((string) config('services.sms.twilio.auth_token', ''));
        $from = trim((string) config('services.sms.twilio.from', ''));

        if ($accountSid === '' || $authToken === '' || $from === '') {
            throw new SmsDeliveryException('Cổng gửi SMS (Twilio) chưa được cấu hình đầy đủ.');
        }

        $e164Phone = $this->phoneNormalizer->toE164($phoneNumber);
        $message = $this->formatter->format($otpCode, $purpose, $ttlSeconds);
        $url = sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', $accountSid);

        try {
            $response = Http::timeout(8)
                ->retry(
                    2,
                    200,
                    fn (Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->withBasicAuth($accountSid, $authToken)
                ->asForm()
                ->post($url, [
                    'From' => $from,
                    'To' => $e164Phone,
                    'Body' => $message,
                ]);
        } catch (Throwable $e) {
            Log::warning('Twilio OTP delivery network failure', [
                'phone_masked' => $this->masking->maskPhoneNumber($phoneNumber),
                'purpose' => $purpose->value,
                'error' => $e->getMessage(),
            ]);

            throw new SmsDeliveryException;
        }

        if (! $response->successful()) {
            Log::warning('Twilio OTP delivery rejected by provider', [
                'phone_masked' => $this->masking->maskPhoneNumber($phoneNumber),
                'purpose' => $purpose->value,
                'http_status' => $response->status(),
            ]);

            throw new SmsDeliveryException;
        }

        $payload = $response->json();
        $reference = is_array($payload) && ! empty($payload['sid'])
            ? (string) $payload['sid']
            : 'TWILIO-'.Str::upper(Str::random(10));

        return [
            'provider' => 'twilio',
            'status' => 'SENT',
            'reference' => $reference,
        ];
    }
}
