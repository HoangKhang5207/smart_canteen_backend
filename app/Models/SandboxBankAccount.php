<?php

namespace App\Models;

use App\Enums\SandboxBankMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'provider_reference',
    'bank_code',
    'account_number_masked',
    'balance',
    'simulated_mode',
])]
class SandboxBankAccount extends Model
{
    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'simulated_mode' => SandboxBankMode::class,
        ];
    }
}
