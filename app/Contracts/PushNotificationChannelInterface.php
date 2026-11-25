<?php

namespace App\Contracts;

use App\Models\CanteenNotification;
use App\Models\User;

interface PushNotificationChannelInterface
{
    public function channelName(): string;

    /**
     * Dispatch a push notification for an already-persisted In-App CanteenNotification.
     * Implementations must NEVER throw into financial workflows; return status=FAILED/SKIPPED gracefully.
     *
     * @return array{channel: string, status: string, reference: ?string}
     */
    public function send(User $recipient, CanteenNotification $notification): array;
}
