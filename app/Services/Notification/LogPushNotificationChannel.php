<?php

namespace App\Services\Notification;

use App\Contracts\PushNotificationChannelInterface;
use App\Models\CanteenNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogPushNotificationChannel implements PushNotificationChannelInterface
{
    public function channelName(): string
    {
        return 'log';
    }

    public function send(User $recipient, CanteenNotification $notification): array
    {
        $reference = 'LOG-PUSH-'.Str::upper(Str::random(10));

        Log::info('Canteen push notification dispatched (log driver)', [
            'channel' => $this->channelName(),
            'status' => 'SIMULATED',
            'reference' => $reference,
            'notification_id' => $notification->id,
            'user_id' => $recipient->id,
            'type' => $notification->type?->value ?? (string) $notification->type,
            'severity' => $notification->severity?->value ?? (string) $notification->severity,
        ]);

        return [
            'channel' => $this->channelName(),
            'status' => 'SIMULATED',
            'reference' => $reference,
        ];
    }
}
