<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_list_all_orders_and_filter_by_status_or_code(): void
    {
        $customerA = User::factory()->create(['name' => 'Khách A']);
        $customerB = User::factory()->create(['name' => 'Khách B']);
        $staff = User::factory()->create(['role' => UserRole::STAFF]);

        $order1 = Order::factory()->for($customerA)->create([
            'code' => 'SC-261114-000101',
            'status' => OrderStatus::PENDING,
            'total_amount' => 45000,
        ]);
        $order2 = Order::factory()->for($customerB)->create([
            'code' => 'SC-261114-000102',
            'status' => OrderStatus::READY,
            'total_amount' => 90000,
        ]);

        Sanctum::actingAs($staff);

        $allResponse = $this->getJson('/api/v1/admin/orders');
        $allResponse->assertOk()->assertJsonCount(2, 'data');

        $filteredStatus = $this->getJson('/api/v1/admin/orders?status=READY');
        $filteredStatus->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order2->id)
            ->assertJsonPath('data.0.customer_name', 'Khách B');

        $filteredSearch = $this->getJson('/api/v1/admin/orders?search=000101');
        $filteredSearch->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order1->id);
    }

    public function test_staff_transitions_order_through_valid_states_increments_version_and_writes_status_logs(): void
    {
        $customer = User::factory()->create();
        $staff = User::factory()->create(['role' => UserRole::STAFF, 'name' => 'Nhân viên Bếp']);
        $order = Order::factory()->for($customer)->create([
            'status' => OrderStatus::PENDING,
            'version' => 1,
        ]);

        Sanctum::actingAs($staff);

        $step1 = $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'PREPARING',
            'version' => 1,
        ]);

        $step1->assertOk()
            ->assertJsonPath('data.status', 'PREPARING')
            ->assertJsonPath('data.version', 2)
            ->assertJsonCount(1, 'data.status_logs')
            ->assertJsonPath('data.status_logs.0.from_status', 'PENDING')
            ->assertJsonPath('data.status_logs.0.to_status', 'PREPARING')
            ->assertJsonPath('data.status_logs.0.changed_by_name', 'Nhân viên Bếp');

        $step2 = $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'READY',
            'version' => 2,
        ]);

        $step2->assertOk()
            ->assertJsonPath('data.status', 'READY')
            ->assertJsonPath('data.version', 3)
            ->assertJsonCount(2, 'data.status_logs');

        $step3 = $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'COMPLETED',
            'version' => 3,
        ]);

        $step3->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.version', 4)
            ->assertJsonCount(3, 'data.status_logs');
    }

    public function test_invalid_status_transition_returns_422_and_writes_no_log(): void
    {
        $customer = User::factory()->create();
        $staff = User::factory()->create(['role' => UserRole::STAFF]);
        $order = Order::factory()->for($customer)->create([
            'status' => OrderStatus::PENDING,
            'version' => 1,
        ]);

        Sanctum::actingAs($staff);

        // Cannot jump from PENDING directly to READY or COMPLETED
        $response = $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'READY',
            'version' => 1,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'PENDING',
            'version' => 1,
        ]);
        $this->assertDatabaseCount('order_status_logs', 0);
    }

    public function test_stale_version_returns_409_conflict_and_prevents_concurrent_overwrite(): void
    {
        $customer = User::factory()->create();
        $staff1 = User::factory()->create(['role' => UserRole::STAFF]);
        $staff2 = User::factory()->create(['role' => UserRole::ADMIN]);

        $order = Order::factory()->for($customer)->create([
            'status' => OrderStatus::PENDING,
            'version' => 1,
        ]);

        Sanctum::actingAs($staff1);
        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'PREPARING',
            'version' => 1,
        ])->assertOk();

        // Second staff still holds stale version = 1
        Sanctum::actingAs($staff2);
        $conflict = $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'CANCELLED',
            'version' => 1,
        ]);

        $conflict->assertStatus(409);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'PREPARING',
            'version' => 2,
        ]);
        $this->assertDatabaseCount('order_status_logs', 1);
    }

    public function test_customer_cannot_access_admin_order_endpoints(): void
    {
        $customer = User::factory()->create(['role' => UserRole::CUSTOMER]);
        $order = Order::factory()->for($customer)->create();

        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/admin/orders')->assertStatus(403);
        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'PREPARING',
            'version' => 1,
        ])->assertStatus(403);
    }

    public function test_invalid_status_filter_or_excessive_limit_on_admin_orders_returns_422(): void
    {
        $customer = User::factory()->create();
        $staff = User::factory()->create(['role' => UserRole::STAFF]);

        Order::factory()->count(3)->for($customer)->create([
            'status' => OrderStatus::PENDING,
        ]);

        Sanctum::actingAs($staff);

        // Lowercase status filter is normalized to uppercase and accepted
        $this->getJson('/api/v1/admin/orders?status=pending')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        // Explicit limit or per_page parameter restricts returned rows and returns pagination meta
        $this->getJson('/api/v1/admin/orders?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/v1/admin/orders?page=2&per_page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 3);

        // Invalid status filter returns 422 instead of silently returning unfiltered rows
        $this->getJson('/api/v1/admin/orders?status=INVALID_STATE')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // Limit or per_page above 100 or below 1 returns 422
        $this->getJson('/api/v1/admin/orders?limit=101')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);

        $this->getJson('/api/v1/admin/orders?per_page=101')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }
}
