<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'student_card_id',
    'changed_by',
    'previous_limit',
    'new_limit',
    'reason',
    'created_at',
])]
class SpendingLimitLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'previous_limit' => 'integer',
            'new_limit' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function studentCard(): BelongsTo
    {
        return $this->belongsTo(StudentCard::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
