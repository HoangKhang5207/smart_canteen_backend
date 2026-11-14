<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_zalo_customer_without_email_or_password(): void
    {
        $user = User::factory()->create([
            'name' => 'Nguyễn Văn A',
            'email' => null,
            'password' => null,
            'role' => UserRole::CUSTOMER,
            'zalo_user_id' => 'zalo_123456',
        ]);

        $fresh = $user->fresh();
        $this->assertSame(UserRole::CUSTOMER, $fresh->role);
        $this->assertSame('zalo_123456', $fresh->zalo_user_id);
        $this->assertNull($fresh->email);
    }

    public function test_order_and_items_store_integer_amounts_and_enforce_unique_idempotency_key_per_user(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['price' => 45000]);

        $order = Order::factory()->for($user)->create([
            'code' => 'SC-261114-000001',
            'status' => OrderStatus::PENDING,
            'total_amount' => 90000,
            'idempotency_key' => 'idem-key-1',
            'payload_hash' => hash('sha256', 'payload-1'),
            'version' => 1,
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 45000,
            'subtotal' => 90000,
        ]);

        $this->assertSame(90000, $order->fresh()->total_amount);
        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
        $this->assertSame(45000, $item->fresh()->unit_price);
        $this->assertSame(90000, $item->fresh()->subtotal);

        $this->expectException(QueryException::class);
        Order::factory()->for($user)->create([
            'code' => 'SC-261114-000002',
            'idempotency_key' => 'idem-key-1',
        ]);
    }

    public function test_order_status_state_machine_allowed_transitions(): void
    {
        $this->assertSame(
            [OrderStatus::PREPARING, OrderStatus::CANCELLED],
            OrderStatus::PENDING->allowedTransitions(),
        );
        $this->assertSame(
            [OrderStatus::READY, OrderStatus::CANCELLED],
            OrderStatus::PREPARING->allowedTransitions(),
        );
        $this->assertSame(
            [OrderStatus::COMPLETED],
            OrderStatus::READY->allowedTransitions(),
        );
        $this->assertSame([], OrderStatus::COMPLETED->allowedTransitions());
        $this->assertSame([], OrderStatus::CANCELLED->allowedTransitions());
    }
}
