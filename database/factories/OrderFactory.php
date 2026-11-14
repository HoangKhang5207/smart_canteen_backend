<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'code' => 'SC-'.now()->format('ymd').'-'.strtoupper(Str::random(6)),
            'status' => OrderStatus::PENDING,
            'total_amount' => 45000,
            'note' => null,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', (string) Str::uuid()),
            'version' => 1,
        ];
    }
}
