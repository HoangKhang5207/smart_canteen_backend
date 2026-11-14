<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStatisticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_today_summary_counting_only_today_orders_and_completed_revenue(): void
    {
        $customer = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        // Today orders
        Order::factory()->for($customer)->create([
            'status' => OrderStatus::PENDING,
            'total_amount' => 45000,
            'created_at' => now()->startOfDay()->addHours(8),
        ]);
        Order::factory()->for($customer)->create([
            'status' => OrderStatus::PREPARING,
            'total_amount' => 55000,
            'created_at' => now()->startOfDay()->addHours(9),
        ]);
        Order::factory()->for($customer)->create([
            'status' => OrderStatus::COMPLETED,
            'total_amount' => 90000,
            'created_at' => now()->startOfDay()->addHours(10),
        ]);
        Order::factory()->for($customer)->create([
            'status' => OrderStatus::COMPLETED,
            'total_amount' => 60000,
            'created_at' => now()->startOfDay()->addHours(11),
        ]);
        Order::factory()->for($customer)->create([
            'status' => OrderStatus::CANCELLED,
            'total_amount' => 30000,
            'created_at' => now()->startOfDay()->addHours(11),
        ]);

        // Yesterday completed order (must not be included in today stats)
        Order::factory()->for($customer)->create([
            'status' => OrderStatus::COMPLETED,
            'total_amount' => 500000,
            'created_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/statistics');

        $response->assertOk()
            ->assertJsonPath('data.date', now()->toDateString())
            ->assertJsonPath('data.today_total_orders', 5)
            ->assertJsonPath('data.today_completed_revenue', 150000)
            ->assertJsonPath('data.today_active_orders', 2)
            ->assertJsonPath('data.counts_by_status.PENDING', 1)
            ->assertJsonPath('data.counts_by_status.PREPARING', 1)
            ->assertJsonPath('data.counts_by_status.READY', 0)
            ->assertJsonPath('data.counts_by_status.COMPLETED', 2)
            ->assertJsonPath('data.counts_by_status.CANCELLED', 1);
    }

    public function test_customer_cannot_access_statistics_endpoint(): void
    {
        $customer = User::factory()->create(['role' => UserRole::CUSTOMER]);
        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/admin/statistics')->assertStatus(403);
    }
}
