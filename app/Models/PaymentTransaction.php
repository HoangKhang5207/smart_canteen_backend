<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id',
    'student_card_id',
    'bank_link_id',
    'user_id',
    'provider',
    'type',
    'amount',
    'status',
    'failure_code',
    'failure_message',
    'idempotency_key',
    'provider_reference',
    'gateway_txn_ref',
    'response_meta',
    'processed_at',
])]
class PaymentTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'response_meta' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function studentCard(): BelongsTo
    {
        return $this->belongsTo(StudentCard::class);
    }

    public function bankLink(): BelongsTo
    {
        return $this->belongsTo(BankLink::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
