<?php

namespace App\Models;

use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'bank_link_id',
    'purpose',
    'phone_masked',
    'code_hash',
    'attempts',
    'max_attempts',
    'resend_count',
    'max_resends',
    'last_sent_at',
    'expires_at',
    'verified_at',
    'invalidated_at',
])]
#[Hidden(['code_hash'])]
class OtpVerification extends Model
{
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'resend_count' => 'integer',
            'max_resends' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bankLink(): BelongsTo
    {
        return $this->belongsTo(BankLink::class);
    }
}
