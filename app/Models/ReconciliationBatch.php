<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reconciliation_date',
    'provider',
    'status',
    'total_local_count',
    'total_provider_count',
    'matched_count',
    'discrepancy_count',
    'total_local_amount',
    'total_provider_amount',
    'run_by',
    'completed_at',
])]
class ReconciliationBatch extends Model
{
    protected function casts(): array
    {
        return [
            'reconciliation_date' => 'date:Y-m-d',
            'total_local_count' => 'integer',
            'total_provider_count' => 'integer',
            'matched_count' => 'integer',
            'discrepancy_count' => 'integer',
            'total_local_amount' => 'integer',
            'total_provider_amount' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReconciliationItem::class, 'batch_id');
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
