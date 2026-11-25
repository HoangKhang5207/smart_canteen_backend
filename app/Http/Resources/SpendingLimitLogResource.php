<?php

namespace App\Http\Resources;

use App\Models\SpendingLimitLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SpendingLimitLog
 */
class SpendingLimitLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'previous_limit' => (int) $this->previous_limit,
            'new_limit' => (int) $this->new_limit,
            'reason' => $this->reason,
            'changed_by_name' => $this->whenLoaded('actor', fn () => $this->actor?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
