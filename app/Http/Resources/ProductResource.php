<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $stock = $this->stock_quantity !== null ? (int) $this->stock_quantity : null;
        $threshold = (int) ($this->low_stock_threshold ?? 5);
        $isAvailable = (bool) $this->is_available;

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => (int) $this->price,
            'image_url' => $this->image_url,
            'is_available' => $isAvailable,
            'stock_quantity' => $stock,
            'low_stock_threshold' => $threshold,
            'is_in_stock' => $isAvailable && ($stock === null || $stock > 0),
        ];
    }
}
