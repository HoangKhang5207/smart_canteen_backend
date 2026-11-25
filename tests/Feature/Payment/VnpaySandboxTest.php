<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\Payment\VnpayGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VnpaySandboxTest extends TestCase
{
    use RefreshDatabase;

    private function createVnpayOrder(User $user, int $price = 40000): Order
    {
        $category = Category::firstOrCreate(['name' => 'VNPay Test Cat'], ['is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Bún bò VNPay',
            'price' => $price,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);
        $res = $this->withHeader('Idempotency-Key', 'vnp-ord-'.uniqid())
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => PaymentMethod::VNPAY->value,
            ])->assertCreated()
            ->assertJsonPath('data.payment_method', PaymentMethod::VNPAY->value)
            ->assertJsonPath('data.payment_status', PaymentStatus::UNPAID->value);

        return Order::findOrFail((int) $res->json('data.id'));
    }

    public function test_vnpay_create_url_ipn_success_idempotency_and_return_verification(): void
    {
        config()->set('services.vnpay.tmn_code', 'DEMO_TMN');
        config()->set('services.vnpay.hash_secret', 'VNPAY_TEST_SECRET_512');

        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $order = $this->createVnpayOrder($user, 40000);

        // Stranger cannot create payment URL for owner's order (404)
        Sanctum::actingAs($stranger);
        $this->postJson('/api/v1/payments/vnpay/create-url', ['order_id' => $order->id])
            ->assertNotFound();

        Sanctum::actingAs($user);
        $createRes = $this->postJson('/api/v1/payments/vnpay/create-url', ['order_id' => $order->id])
            ->assertCreated()
            ->assertJsonPath('data.amount', 40000)
            ->assertJsonPath('data.simulated', false);

        $paymentUrl = (string) $createRes->json('data.payment_url');
        $txnRef = (string) $createRes->json('data.txn_ref');
        $this->assertStringContainsString('vnp_SecureHash=', $paymentUrl);

        $vnpay = app(VnpayGatewayService::class);
        $ipnParams = [
            'vnp_TmnCode' => 'DEMO_TMN',
            'vnp_Amount' => '4000000',
            'vnp_TxnRef' => $txnRef,
            'vnp_ResponseCode' => '00',
            'vnp_TransactionNo' => '14567890',
        ];
        $ipnParams['vnp_SecureHash'] = $vnpay->signParams($ipnParams, 'VNPAY_TEST_SECRET_512');

        // 1. Valid IPN marks order PAID & tx SUCCEEDED
        $this->getJson('/api/v1/payments/vnpay/ipn?'.http_build_query($ipnParams))
            ->assertOk()
            ->assertJson(['RspCode' => '00', 'Message' => 'Confirm Success']);

        $this->assertSame(PaymentStatus::PAID, $order->fresh()->payment_status);
        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order->id,
            'provider' => 'vnpay',
            'gateway_txn_ref' => $txnRef,
            'status' => 'SUCCEEDED',
            'provider_reference' => '14567890',
        ]);

        // 2. Duplicate IPN returns RspCode 02 (idempotent)
        $this->getJson('/api/v1/payments/vnpay/ipn?'.http_build_query($ipnParams))
            ->assertOk()
            ->assertJson(['RspCode' => '02']);

        // 3. Return URL verifies signature
        $this->getJson('/api/v1/payments/vnpay/return?'.http_build_query($ipnParams))
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.success', true);
    }

    public function test_vnpay_ipn_rejects_bad_checksum_amount_mismatch_and_handles_failed_code_51(): void
    {
        config()->set('services.vnpay.tmn_code', 'DEMO_TMN');
        config()->set('services.vnpay.hash_secret', 'VNPAY_TEST_SECRET_512');

        $user = User::factory()->create();
        $order = $this->createVnpayOrder($user, 50000);

        Sanctum::actingAs($user);
        $txnRef = (string) $this->postJson('/api/v1/payments/vnpay/create-url', ['order_id' => $order->id])
            ->assertCreated()
            ->json('data.txn_ref');

        $vnpay = app(VnpayGatewayService::class);

        // Bad checksum -> 97
        $this->getJson('/api/v1/payments/vnpay/ipn?vnp_TxnRef='.$txnRef.'&vnp_Amount=5000000&vnp_ResponseCode=00&vnp_SecureHash=invalid')
            ->assertOk()
            ->assertJson(['RspCode' => '97']);

        // Wrong amount -> 04
        $wrongAmt = [
            'vnp_TmnCode' => 'DEMO_TMN',
            'vnp_Amount' => '1000000',
            'vnp_TxnRef' => $txnRef,
            'vnp_ResponseCode' => '00',
        ];
        $wrongAmt['vnp_SecureHash'] = $vnpay->signParams($wrongAmt, 'VNPAY_TEST_SECRET_512');
        $this->getJson('/api/v1/payments/vnpay/ipn?'.http_build_query($wrongAmt))
            ->assertOk()
            ->assertJson(['RspCode' => '04']);

        // Insufficient funds (code 51) -> marks tx FAILED & order FAILED
        $failed51 = [
            'vnp_TmnCode' => 'DEMO_TMN',
            'vnp_Amount' => '5000000',
            'vnp_TxnRef' => $txnRef,
            'vnp_ResponseCode' => '51',
            'vnp_TransactionNo' => '14567891',
        ];
        $failed51['vnp_SecureHash'] = $vnpay->signParams($failed51, 'VNPAY_TEST_SECRET_512');
        $this->getJson('/api/v1/payments/vnpay/ipn?'.http_build_query($failed51))
            ->assertOk()
            ->assertJson(['RspCode' => '00']);

        $this->assertSame(PaymentStatus::FAILED, $order->fresh()->payment_status);
        $tx = PaymentTransaction::where('gateway_txn_ref', $txnRef)->firstOrFail();
        $this->assertSame('FAILED', $tx->status);
        $this->assertSame('INSUFFICIENT_BANK_BALANCE', $tx->failure_code);

        // Replaying FAILED IPN returns 02 (idempotent, never mutates FAILED -> SUCCEEDED)
        $this->getJson('/api/v1/payments/vnpay/ipn?'.http_build_query($failed51))
            ->assertOk()
            ->assertJson(['RspCode' => '02']);
    }

    public function test_vnpay_security_edge_cases_config_missing_cancelled_order_and_array_query_params(): void
    {
        // 1. Fail-closed 503 when VNPAY config missing and simulator disabled
        config()->set('services.vnpay.tmn_code', '');
        config()->set('services.vnpay.hash_secret', '');
        config()->set('services.vnpay.sandbox_simulator_enabled', false);

        $user = User::factory()->create();
        $order = $this->createVnpayOrder($user, 35000);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/payments/vnpay/create-url', ['order_id' => $order->id])
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'VNPAY_CONFIG_MISSING');

        // Configure valid sandbox keys
        config()->set('services.vnpay.tmn_code', 'DEMO_TMN');
        config()->set('services.vnpay.hash_secret', 'VNPAY_TEST_SECRET_512');

        // 2. Reject create-url for COUNTER_CASH order
        $category = Category::firstOrCreate(['name' => 'Cash Cat'], ['is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Trà đá',
            'price' => 10000,
            'is_available' => true,
        ]);
        $cashOrderRes = $this->withHeader('Idempotency-Key', 'cash-ord-'.uniqid())
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => PaymentMethod::COUNTER_CASH->value,
            ])->assertCreated();
        $this->postJson('/api/v1/payments/vnpay/create-url', ['order_id' => (int) $cashOrderRes->json('data.id')])
            ->assertUnprocessable();

        // 3. Array query parameters do not cause 500 error
        $this->getJson('/api/v1/payments/vnpay/ipn?vnp_SecureHash[]=1&vnp_TxnRef[]=abc')
            ->assertOk()
            ->assertJson(['RspCode' => '97']);
        $this->getJson('/api/v1/payments/vnpay/return?vnp_SecureHash[]=1&vnp_TxnRef[]=abc')
            ->assertStatus(400)
            ->assertJsonPath('data.valid', false);

        // 4. Unknown txn_ref returns RspCode 01
        $vnpay = app(VnpayGatewayService::class);
        $unknown = [
            'vnp_TmnCode' => 'DEMO_TMN',
            'vnp_Amount' => '3500000',
            'vnp_TxnRef' => 'VNP-999999-UNKNOWN',
            'vnp_ResponseCode' => '00',
            'vnp_TransactionStatus' => '00',
        ];
        $unknown['vnp_SecureHash'] = $vnpay->signParams($unknown, 'VNPAY_TEST_SECRET_512');
        $this->getJson('/api/v1/payments/vnpay/ipn?'.http_build_query($unknown))
            ->assertOk()
            ->assertJson(['RspCode' => '01']);

        // 5. IPN success on a CANCELLED order flags PENDING_RECONCILE instead of PAID
        $txnRef = (string) $this->postJson('/api/v1/payments/vnpay/create-url', ['order_id' => $order->id])
            ->assertCreated()
            ->json('data.txn_ref');

        $order->update(['status' => OrderStatus::CANCELLED]);

        // create-url rejects CANCELLED order
        $this->postJson('/api/v1/payments/vnpay/create-url', ['order_id' => $order->id])
            ->assertUnprocessable();

        $lateIpn = [
            'vnp_TmnCode' => 'DEMO_TMN',
            'vnp_Amount' => '3500000',
            'vnp_TxnRef' => $txnRef,
            'vnp_ResponseCode' => '00',
            'vnp_TransactionStatus' => '00',
            'vnp_TransactionNo' => '14999999',
        ];
        $lateIpn['vnp_SecureHash'] = $vnpay->signParams($lateIpn, 'VNPAY_TEST_SECRET_512');
        $this->getJson('/api/v1/payments/vnpay/ipn?'.http_build_query($lateIpn))
            ->assertOk()
            ->assertJson(['RspCode' => '00']);

        $this->assertSame(PaymentStatus::PENDING_RECONCILE, $order->fresh()->payment_status);

        // 6. Reject create-url for PENDING_RECONCILE / PAID / REFUNDED orders
        $order->update(['status' => OrderStatus::PENDING, 'payment_status' => PaymentStatus::PENDING_RECONCILE]);
        $this->postJson('/api/v1/payments/vnpay/create-url', ['order_id' => $order->id])->assertUnprocessable();
        $order->update(['payment_status' => PaymentStatus::PAID]);
        $this->postJson('/api/v1/payments/vnpay/create-url', ['order_id' => $order->id])->assertUnprocessable();
        $order->update(['payment_status' => PaymentStatus::REFUNDED]);
        $this->postJson('/api/v1/payments/vnpay/create-url', ['order_id' => $order->id])->assertUnprocessable();
    }
}
