<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'student_card_id',
    'daily_limit',
    'spent_today',
    'spent_date',
])]
class SpendingLimit extends Model
{
    protected function casts(): array
    {
        return [
            'daily_limit' => 'integer',
            'spent_today' => 'integer',
            'spent_date' => 'string',
        ];
    }

    public function studentCard(): BelongsTo
    {
        return $this->belongsTo(StudentCard::class);
    }
}
