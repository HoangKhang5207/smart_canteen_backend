<?php

namespace App\Http\Resources;

use App\Models\StudentCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StudentCard
 */
class StudentCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $activeLink = $this->activeBankLink;

        return [
            'id' => $this->id,
            'card_code' => $this->card_code,
            'status' => $this->status->value,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'student' => new StudentResource($this->student),
            'is_bank_linked' => $activeLink !== null,
            'bank_link' => $activeLink !== null ? new BankLinkResource($activeLink) : null,
        ];
    }
}
