<?php

namespace App\Models;

use App\Enums\NotificationSeverity;
use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'type',
    'severity',
    'title',
    'body',
    'action_url',
    'meta',
    'push_channel',
    'push_status',
    'push_reference',
    'read_at',
])]
class CanteenNotification extends Model
{
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'severity' => NotificationSeverity::class,
            'meta' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
