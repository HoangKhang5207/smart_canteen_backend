<?php

namespace App\Services\Notification;

use App\Contracts\PushNotificationChannelInterface;
use App\Models\CanteenNotification;
use App\Models\User;
use App\Services\PhoneNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ZaloZnsPushChannel implements PushNotificationChannelInterface
{
    public function __construct(
        private readonly PhoneNormalizer $phoneNormalizer,
    ) {}

    public function channelName(): string
    {
        return 'zalo_zns';
    }

    public function send(User $recipient, CanteenNotification $notification): array
    {
        $accessToken = trim((string) config('services.notifications.zalo_zns.access_token', ''));
        $templateId = trim((string) config('services.notifications.zalo_zns.template_id', ''));
        $endpoint = trim((string) config('services.notifications.zalo_zns.endpoint', 'https://business.zalo.me/api/template/message'));
        $sandbox = (bool) config('services.notifications.zalo_zns.sandbox', true);

        $rawPhone = is_string($recipient->phone) ? trim($recipient->phone) : '';
        $normalized = $rawPhone !== '' ? $this->phoneNormalizer->normalizeVietnamMobile($rawPhone) : null;

        if ($accessToken === '' || $templateId === '' || $endpoint === '' || $normalized === null) {
            return [
                'channel' => $this->channelName(),
                'status' => 'SKIPPED',
                'reference' => null,
            ];
        }

        $znsPhone = '84'.substr($normalized, 1);

        $bodyPayload = [
            'phone' => $znsPhone,
            'template_id' => $templateId,
            'template_data' => [
                'title' => mb_substr($notification->title, 0, 120),
                'content' => mb_substr($notification->body, 0, 200),
                'type' => $notification->type?->value ?? (string) $notification->type,
            ],
            'tracking_id' => 'sc-notif-'.$notification->id,
        ];

        if ($sandbox) {
            $bodyPayload['mode'] = 'development';
        }

        try {
            $response = Http::timeout(6)
                ->withHeaders([
                    'access_token' => $accessToken,
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, $bodyPayload);

            $payload = $response->json();
            $rawError = is_array($payload) && array_key_exists('error', $payload) ? $payload['error'] : null;
            $errorCode = (is_int($rawError) || (is_string($rawError) && preg_match('/^-?\d+$/', $rawError) === 1))
                ? (int) $rawError
                : -1;

            if ($response->successful() && $errorCode === 0) {
                $msgId = is_array($payload['data'] ?? null) ? ($payload['data']['msg_id'] ?? null) : null;

                return [
                    'channel' => $this->channelName(),
                    'status' => 'SENT',
                    'reference' => is_string($msgId) && $msgId !== '' ? mb_substr($msgId, 0, 120) : null,
                ];
            }

            Log::warning('Zalo ZNS push notification rejected', [
                'notification_id' => $notification->id,
                'user_id' => $recipient->id,
                'http_status' => $response->status(),
                'zns_error' => $errorCode,
            ]);

            return [
                'channel' => $this->channelName(),
                'status' => 'FAILED',
                'reference' => null,
            ];
        } catch (Throwable $e) {
            Log::warning('Zalo ZNS push notification transport failed', [
                'notification_id' => $notification->id,
                'user_id' => $recipient->id,
                'error' => mb_substr($e->getMessage(), 0, 180),
            ]);

            return [
                'channel' => $this->channelName(),
                'status' => 'FAILED',
                'reference' => null,
            ];
        }
    }
}
