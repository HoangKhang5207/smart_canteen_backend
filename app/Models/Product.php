<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'price',
        'image_url',
        'is_available',
        'stock_quantity',
        'low_stock_threshold',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_available' => 'boolean',
            'stock_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function isInStock(): bool
    {
        if (! $this->is_available) {
            return false;
        }

        return $this->stock_quantity === null || (int) $this->stock_quantity > 0;
    }

    public function isLowStock(): bool
    {
        if ($this->stock_quantity === null) {
            return false;
        }

        $threshold = (int) ($this->low_stock_threshold ?? 5);

        return (int) $this->stock_quantity > 0 && (int) $this->stock_quantity <= $threshold;
    }
}
