<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EndToEndDemoWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_eight_step_smart_canteen_demo_workflow_end_to_end(): void
    {
        config(['services.demo.login_enabled' => true]);

        // Step 0: Seed mock categories, products, and staff/admin accounts (AC-01, AC-12)
        $this->seed(DatabaseSeeder::class);

        // Step 1 & 2: Customer opens Smart Canteen Mini App, authenticates, and browses menu (AC-01, AC-02)
        $categoriesRes = $this->getJson('/api/v1/categories')->assertOk();
        $this->assertGreaterThanOrEqual(3, count($categoriesRes->json('data')));

        $productsRes = $this->getJson('/api/v1/products')->assertOk();
        $products = $productsRes->json('data');
        $this->assertGreaterThanOrEqual(10, count($products));

        $availableProducts = array_values(array_filter($products, fn (array $p) => $p['is_available'] === true));
        $firstItem = $availableProducts[0];
        $secondItem = $availableProducts[1];

        $this->getJson("/api/v1/products/{$firstItem['id']}")
            ->assertOk()
            ->assertJsonPath('data.name', $firstItem['name']);

        $authRes = $this->postJson('/api/v1/auth/demo', ['demo_key' => 'director_demo'])->assertOk();
        $customerToken = $authRes->json('data.token');
        $this->assertNotEmpty($customerToken);

        // Step 3 & 4: Customer creates order with Idempotency-Key; backend computes price and ignores client total (AC-04, AC-06)
        $idempotencyKey = (string) Str::uuid();
        $orderPayload = [
            'items' => [
                ['product_id' => $firstItem['id'], 'quantity' => 2],
                ['product_id' => $secondItem['id'], 'quantity' => 1],
            ],
            'note' => 'Ít cơm, lấy thêm canh',
            'total_amount' => 1, // forged client total must be ignored
            'unit_price' => 1,
        ];

        $expectedTotal = ((int) $firstItem['price'] * 2) + ((int) $secondItem['price'] * 1);

        $createRes = $this->withHeaders([
            'Authorization' => "Bearer {$customerToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/orders', $orderPayload);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.total_amount', $expectedTotal);

        $orderId = (int) $createRes->json('data.id');
        $orderCode = (string) $createRes->json('data.code');
        $this->assertStringStartsWith('SC-', $orderCode);

        // Duplicate tap with same Idempotency-Key returns 200 OK with the exact same order
        $retryRes = $this->withHeaders([
            'Authorization' => "Bearer {$customerToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/orders', $orderPayload);

        $retryRes->assertStatus(200)->assertJsonPath('data.id', $orderId);

        // Step 5: Staff opens Admin dispatch board and sees the newly created PENDING order (AC-07)
        $staff = User::query()->where('email', 'staff@smartcanteen.local')->firstOrFail();
        Sanctum::actingAs($staff);

        $adminListRes = $this->getJson('/api/v1/admin/orders?status=PENDING&search='.$orderCode);
        $adminListRes->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $orderId)
            ->assertJsonPath('data.0.code', $orderCode);

        // Step 6: Staff transitions order PENDING (v1) -> PREPARING (v2) -> READY (v3) (AC-08)
        $toPreparing = $this->patchJson("/api/v1/admin/orders/{$orderId}/status", [
            'status' => 'PREPARING',
            'version' => 1,
        ]);
        $toPreparing->assertOk()
            ->assertJsonPath('data.status', 'PREPARING')
            ->assertJsonPath('data.version', 2);

        $toReady = $this->patchJson("/api/v1/admin/orders/{$orderId}/status", [
            'status' => 'READY',
            'version' => 2,
        ]);
        $toReady->assertOk()
            ->assertJsonPath('data.status', 'READY')
            ->assertJsonPath('data.version', 3)
            ->assertJsonCount(2, 'data.status_logs');

        // Step 7 & 8: Customer polls order detail & order history on Mini App and sees READY status; another user gets 404 (AC-09, AC-10)
        $customerUser = User::query()->where('zalo_user_id', 'demo_director_demo')->firstOrFail();
        Sanctum::actingAs($customerUser);

        $trackedOrder = $this->getJson("/api/v1/orders/{$orderId}");
        $trackedOrder->assertOk()
            ->assertJsonPath('data.code', $orderCode)
            ->assertJsonPath('data.status', 'READY')
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.total_amount', $expectedTotal);

        $historyRes = $this->getJson('/api/v1/orders');
        $historyRes->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $orderCode)
            ->assertJsonPath('data.0.status', 'READY');

        $otherCustomer = User::factory()->create();
        Sanctum::actingAs($otherCustomer);
        $this->getJson("/api/v1/orders/{$orderId}")->assertStatus(404);

        // Final Completion & Price Immutability Check (AC-08, AC-11)
        $admin = User::query()->where('email', 'admin@smartcanteen.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/orders/{$orderId}/status", [
            'status' => 'COMPLETED',
            'version' => 3,
        ])->assertOk()->assertJsonPath('data.status', 'COMPLETED');

        // Admin changes product price afterwards -> historical order remains unchanged (AC-11)
        Product::query()->whereKey($firstItem['id'])->update(['price' => 99000]);

        $statsRes = $this->getJson('/api/v1/admin/statistics')->assertOk();
        $statsRes->assertJsonPath('data.today_total_orders', 1)
            ->assertJsonPath('data.today_completed_revenue', $expectedTotal)
            ->assertJsonPath('data.counts_by_status.COMPLETED', 1);

        Sanctum::actingAs($customerUser);
        $this->getJson("/api/v1/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.total_amount', $expectedTotal);
    }
}
