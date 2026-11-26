<?php

namespace App\Http\Resources;

use App\Enums\NotificationSeverity;
use App\Enums\NotificationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CanteenNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->type instanceof NotificationType ? $this->type->value : (string) $this->type;
        $severity = $this->severity instanceof NotificationSeverity ? $this->severity->value : (string) $this->severity;

        return [
            'id' => $this->id,
            'type' => $type,
            'severity' => $severity,
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->action_url,
            'meta' => $this->meta ?? [],
            'push_channel' => $this->push_channel,
            'push_status' => $this->push_status,
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
