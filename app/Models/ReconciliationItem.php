<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'batch_id',
    'payment_transaction_id',
    'provider_reference',
    'order_code',
    'local_amount',
    'provider_amount',
    'status',
    'note',
    'resolution_note',
    'resolved_at',
    'resolved_by',
])]
class ReconciliationItem extends Model
{
    protected function casts(): array
    {
        return [
            'local_amount' => 'integer',
            'provider_amount' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ReconciliationBatch::class, 'batch_id');
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
