<?php

namespace App\Http\Resources;

use App\Models\BankLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankLink
 */
class BankLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_code' => $this->bank_code,
            'bank_name' => $this->bank_name,
            'account_number_masked' => $this->account_number_masked,
            'account_holder_name' => $this->account_holder_name,
            'national_id_masked' => $this->national_id_masked,
            'phone_masked' => $this->phone_masked,
            'email' => $this->email,
            'status' => $this->status->value,
            'auto_debit_agreed' => (bool) $this->auto_debit_agreed,
            'linked_at' => $this->linked_at?->toIso8601String(),
        ];
    }
}
