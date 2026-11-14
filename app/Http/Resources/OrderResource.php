<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof OrderStatus ? $this->status->value : (string) $this->status;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'code' => $this->code,
            'status' => $status,
            'total_amount' => (int) $this->total_amount,
            'note' => $this->note,
            'version' => (int) $this->version,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
