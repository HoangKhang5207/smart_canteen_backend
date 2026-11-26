<?php

namespace App\Services\Notification;

use App\Contracts\PushNotificationChannelInterface;
use App\Models\CanteenNotification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ZaloOaPushChannel implements PushNotificationChannelInterface
{
    public function channelName(): string
    {
        return 'zalo_oa';
    }

    public function send(User $recipient, CanteenNotification $notification): array
    {
        $accessToken = trim((string) config('services.notifications.zalo_oa.access_token', ''));
        $endpoint = trim((string) config('services.notifications.zalo_oa.endpoint', 'https://openapi.zalo.me/v3.0/oa/message/cs'));
        $zaloUserId = is_string($recipient->zalo_user_id) ? trim($recipient->zalo_user_id) : '';

        if ($accessToken === '' || $endpoint === '' || $zaloUserId === '' || str_starts_with($zaloUserId, 'demo_')) {
            return [
                'channel' => $this->channelName(),
                'status' => 'SKIPPED',
                'reference' => null,
            ];
        }

        try {
            $response = Http::timeout(6)
                ->withHeaders([
                    'access_token' => $accessToken,
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, [
                    'recipient' => [
                        'user_id' => $zaloUserId,
                    ],
                    'message' => [
                        'text' => sprintf('[Smart Canteen] %s — %s', $notification->title, $notification->body),
                    ],
                ]);

            $payload = $response->json();
            $rawError = is_array($payload) && array_key_exists('error', $payload) ? $payload['error'] : null;
            $errorCode = (is_int($rawError) || (is_string($rawError) && preg_match('/^-?\d+$/', $rawError) === 1))
                ? (int) $rawError
                : -1;

            if ($response->successful() && $errorCode === 0) {
                $msgId = is_array($payload['data'] ?? null) ? ($payload['data']['message_id'] ?? null) : null;

                return [
                    'channel' => $this->channelName(),
                    'status' => 'SENT',
                    'reference' => is_string($msgId) && $msgId !== '' ? mb_substr($msgId, 0, 120) : null,
                ];
            }

            Log::warning('Zalo OA push notification rejected', [
                'notification_id' => $notification->id,
                'user_id' => $recipient->id,
                'http_status' => $response->status(),
                'zalo_error' => $errorCode,
            ]);

            return [
                'channel' => $this->channelName(),
                'status' => 'FAILED',
                'reference' => null,
            ];
        } catch (Throwable $e) {
            Log::warning('Zalo OA push notification transport failed', [
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
