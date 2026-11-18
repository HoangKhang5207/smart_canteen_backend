<?php

namespace Tests\Feature\Order;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_order_and_computes_total_on_server_ignoring_client_total(): void
    {
        $user = User::factory()->create(['role' => UserRole::CUSTOMER]);
        $category = Category::factory()->create(['is_active' => true]);
        $p1 = Product::factory()->for($category)->create(['name' => 'Cơm sườn', 'price' => 45000, 'is_available' => true]);
        $p2 = Product::factory()->for($category)->create(['name' => 'Trà đào', 'price' => 25000, 'is_available' => true]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                ['product_id' => $p1->id, 'quantity' => 2, 'unit_price' => 1, 'subtotal' => 2],
                ['product_id' => $p2->id, 'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1],
            ],
            'note' => 'Ít đá, thêm ớt',
            'total_amount' => 3,
        ], [
            'Idempotency-Key' => 'order-key-101',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.total_amount', 115000)
            ->assertJsonPath('data.note', 'Ít đá, thêm ớt')
            ->assertJsonPath('data.version', 1)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.unit_price', 45000)
            ->assertJsonPath('data.items.0.subtotal', 90000)
            ->assertJsonPath('data.items.1.unit_price', 25000)
            ->assertJsonPath('data.items.1.subtotal', 25000);

        $this->assertMatchesRegularExpression('/^SC-\d{6}-\d{6}$/', $response->json('data.code'));
    }

    public function test_rejects_unavailable_or_inactive_category_product_or_invalid_quantity(): void
    {
        $user = User::factory()->create(['role' => UserRole::CUSTOMER]);
        $activeCat = Category::factory()->create(['is_active' => true]);
        $inactiveCat = Category::factory()->inactive()->create();

        $unavailableProduct = Product::factory()->for($activeCat)->unavailable()->create(['price' => 40000]);
        $inactiveCatProduct = Product::factory()->for($inactiveCat)->create(['price' => 30000, 'is_available' => true]);
        $validProduct = Product::factory()->for($activeCat)->create(['price' => 35000, 'is_available' => true]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $unavailableProduct->id, 'quantity' => 1]],
        ], ['Idempotency-Key' => 'k-unavail'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $inactiveCatProduct->id, 'quantity' => 1]],
        ], ['Idempotency-Key' => 'k-inact'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $validProduct->id, 'quantity' => 0]],
        ], ['Idempotency-Key' => 'k-qty0'])
            ->assertStatus(422);

        $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $validProduct->id, 'quantity' => 21]],
        ], ['Idempotency-Key' => 'k-qty21'])
            ->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_idempotency_key_returns_same_order_on_retry_and_409_on_different_payload(): void
    {
        $user = User::factory()->create(['role' => UserRole::CUSTOMER]);
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['price' => 50000, 'is_available' => true]);

        Sanctum::actingAs($user);

        $first = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'note' => 'Không hành',
        ], ['Idempotency-Key' => 'same-key-001'])->assertStatus(201);

        $second = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'note' => 'Không hành',
        ], ['Idempotency-Key' => 'same-key-001'])->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::count());

        $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'note' => 'Không hành',
        ], ['Idempotency-Key' => 'same-key-001'])
            ->assertStatus(409);

        $this->assertSame(1, Order::count());
    }

    public function test_missing_idempotency_key_header_returns_422(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['price' => 30000]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_changing_product_price_later_does_not_change_existing_order(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['price' => 40000, 'is_available' => true]);

        Sanctum::actingAs($user);

        $created = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ], ['Idempotency-Key' => 'price-lock-1'])->assertStatus(201);

        $orderId = $created->json('data.id');

        $product->update(['price' => 65000]);

        $this->getJson("/api/v1/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.total_amount', 80000)
            ->assertJsonPath('data.items.0.unit_price', 40000)
            ->assertJsonPath('data.items.0.subtotal', 80000);
    }

    public function test_customer_lists_only_own_orders_and_gets_404_for_other_users_order(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['price' => 30000, 'is_available' => true]);

        Sanctum::actingAs($userA);
        $orderA = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], ['Idempotency-Key' => 'user-a-1'])->json('data');

        Sanctum::actingAs($userB);
        $orderB = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ], ['Idempotency-Key' => 'user-b-1'])->json('data');

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $orderB['id']);

        $this->getJson("/api/v1/orders/{$orderA['id']}")
            ->assertNotFound();
    }

    public function test_unauthenticated_order_requests_return_401(): void
    {
        $this->getJson('/api/v1/orders')->assertStatus(401);
        $this->postJson('/api/v1/orders', [])->assertStatus(401);
    }

    public function test_paginates_customer_orders_and_returns_meta(): void
    {
        $user = User::factory()->create(['role' => UserRole::CUSTOMER]);
        Order::factory()->count(8)->for($user)->create();

        Sanctum::actingAs($user);

        $page1 = $this->getJson('/api/v1/orders?page=1&per_page=5');
        $page1->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 8);

        $page2 = $this->getJson('/api/v1/orders?page=2&per_page=5');
        $page2->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 8);

        $this->getJson('/api/v1/orders?per_page=51')->assertStatus(422);
    }
}
