<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderStatusLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fromStatus = $this->from_status instanceof OrderStatus ? $this->from_status->value : (string) $this->from_status;
        $toStatus = $this->to_status instanceof OrderStatus ? $this->to_status->value : (string) $this->to_status;

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by' => $this->changed_by,
            'changed_by_name' => $this->whenLoaded('actor', fn () => $this->actor?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
