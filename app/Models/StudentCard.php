<?php

namespace App\Models;

use App\Enums\BankLinkStatus;
use App\Enums\StudentCardStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'student_id',
    'card_code',
    'status',
    'issued_at',
])]
class StudentCard extends Model
{
    protected function casts(): array
    {
        return [
            'status' => StudentCardStatus::class,
            'issued_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function bankLinks(): HasMany
    {
        return $this->hasMany(BankLink::class);
    }

    public function activeBankLink(): HasOne
    {
        return $this->hasOne(BankLink::class)
            ->where('status', BankLinkStatus::ACTIVE->value);
    }

    public function spendingLimit(): HasOne
    {
        return $this->hasOne(SpendingLimit::class);
    }

    public function spendingLimitLogs(): HasMany
    {
        return $this->hasMany(SpendingLimitLog::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
