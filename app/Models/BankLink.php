<?php

namespace App\Models;

use App\Enums\BankLinkStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'student_card_id',
    'user_id',
    'bank_code',
    'bank_name',
    'account_number_encrypted',
    'account_number_masked',
    'account_holder_name',
    'national_id_encrypted',
    'national_id_masked',
    'phone_number',
    'phone_masked',
    'email',
    'auto_debit_agreed',
    'auto_debit_agreed_at',
    'provider_reference',
    'status',
    'linked_at',
    'unlinked_at',
])]
#[Hidden([
    'account_number_encrypted',
    'national_id_encrypted',
    'phone_number',
    'provider_reference',
])]
class BankLink extends Model
{
    protected function casts(): array
    {
        return [
            'account_number_encrypted' => 'encrypted',
            'national_id_encrypted' => 'encrypted',
            'auto_debit_agreed' => 'boolean',
            'auto_debit_agreed_at' => 'datetime',
            'status' => BankLinkStatus::class,
            'linked_at' => 'datetime',
            'unlinked_at' => 'datetime',
        ];
    }

    public function studentCard(): BelongsTo
    {
        return $this->belongsTo(StudentCard::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function otpVerifications(): HasMany
    {
        return $this->hasMany(OtpVerification::class);
    }
}
