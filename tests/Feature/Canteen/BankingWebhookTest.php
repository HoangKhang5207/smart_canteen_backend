<?php

namespace Tests\Feature\Canteen;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankingWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function sign(string $rawBody, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
    }

    public function test_sandbox_webhook_verifies_hmac_updates_timeout_transaction_and_blocks_replay(): void
    {
        $secret = 'test-webhook-hmac-secret';
        config()->set('services.banking.webhook_secret', $secret);

        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'code' => 'SC-261125-000101',
            'status' => OrderStatus::PENDING,
            'payment_method' => PaymentMethod::STUDENT_CARD,
            'payment_status' => PaymentStatus::PENDING_RECONCILE,
            'total_amount' => 35000,
            'discount_amount' => 0,
            'idempotency_key' => 'wh-order-1',
            'payload_hash' => hash('sha256', 'wh-order-1'),
            'version' => 1,
        ]);

        $tx = PaymentTransaction::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'provider' => 'sandbox_epay',
            'type' => 'CHARGE',
            'amount' => 35000,
            'status' => 'TIMEOUT',
            'failure_code' => 'GATEWAY_TIMEOUT',
            'provider_reference' => 'EPAY-SBX-CHG-WH001',
        ]);

        $payload = [
            'event_id' => 'evt-settle-001',
            'event_type' => 'CHARGE_SETTLED',
            'provider_reference' => 'EPAY-SBX-CHG-WH001',
        ];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $ts = now()->getTimestamp();
        $sig = $this->sign($rawBody, $ts, $secret);

        $this->withHeaders([
            'X-Gateway-Timestamp' => (string) $ts,
            'X-Gateway-Signature' => $sig,
        ])->postJson('/api/v1/webhooks/banking/sandbox', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertSame('SUCCEEDED', $tx->fresh()->status);
        $this->assertSame(PaymentStatus::PAID, $order->fresh()->payment_status);

        // Replaying the same event_id is idempotent
        $this->withHeaders([
            'X-Gateway-Timestamp' => (string) $ts,
            'X-Gateway-Signature' => $sig,
        ])->postJson('/api/v1/webhooks/banking/sandbox', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'already_processed');
    }

    public function test_sandbox_webhook_rejects_invalid_signature_expired_timestamp_and_missing_secret(): void
    {
        $payload = [
            'event_id' => 'evt-bad-001',
            'event_type' => 'CHARGE_SETTLED',
            'provider_reference' => 'EPAY-SBX-CHG-NONE',
        ];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        // Missing secret -> 503 fail-closed
        config()->set('services.banking.webhook_secret', '');
        $this->postJson('/api/v1/webhooks/banking/sandbox', $payload)
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'WEBHOOK_CONFIG_MISSING');

        config()->set('services.banking.webhook_secret', 'real-secret');

        // Invalid signature -> 401
        $nowTs = now()->getTimestamp();
        $this->withHeaders([
            'X-Gateway-Timestamp' => (string) $nowTs,
            'X-Gateway-Signature' => 'deadbeef',
        ])->postJson('/api/v1/webhooks/banking/sandbox', $payload)
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'WEBHOOK_SIGNATURE_INVALID');

        // Expired timestamp (>300s old) -> 401
        $oldTs = $nowTs - 600;
        $this->withHeaders([
            'X-Gateway-Timestamp' => (string) $oldTs,
            'X-Gateway-Signature' => $this->sign($rawBody, $oldTs, 'real-secret'),
        ])->postJson('/api/v1/webhooks/banking/sandbox', $payload)
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'WEBHOOK_TIMESTAMP_EXPIRED');
    }

    public function test_sandbox_webhook_enforces_provider_isolation_and_terminal_order_guards(): void
    {
        $secret = 'test-webhook-guard-secret';
        config()->set('services.banking.webhook_secret', $secret);

        $user = User::factory()->create();

        // 1. Cross-provider isolation: sandbox_epay webhook must NOT mutate a vnpay transaction
        $vnpOrder = Order::create([
            'user_id' => $user->id,
            'code' => 'SC-261125-000201',
            'status' => OrderStatus::PENDING,
            'payment_method' => PaymentMethod::VNPAY,
            'payment_status' => PaymentStatus::UNPAID,
            'total_amount' => 45000,
            'discount_amount' => 0,
            'idempotency_key' => 'wh-vnp-iso',
            'payload_hash' => hash('sha256', 'wh-vnp-iso'),
            'version' => 1,
        ]);
        $vnpTx = PaymentTransaction::create([
            'order_id' => $vnpOrder->id,
            'user_id' => $user->id,
            'provider' => 'vnpay',
            'type' => 'CHARGE',
            'amount' => 45000,
            'status' => 'PENDING',
            'provider_reference' => 'CROSS-PROVIDER-REF',
        ]);

        $crossPayload = [
            'event_id' => 'evt-cross-001',
            'event_type' => 'CHARGE_SETTLED',
            'provider_reference' => 'CROSS-PROVIDER-REF',
        ];
        $ts = now()->getTimestamp();
        $this->withHeaders([
            'X-Gateway-Timestamp' => (string) $ts,
            'X-Gateway-Signature' => $this->sign(json_encode($crossPayload, JSON_THROW_ON_ERROR), $ts, $secret),
        ])->postJson('/api/v1/webhooks/banking/sandbox', $crossPayload)->assertOk();

        $this->assertSame('PENDING', $vnpTx->fresh()->status);
        $this->assertSame(PaymentStatus::UNPAID, $vnpOrder->fresh()->payment_status);

        // 2. CHARGE_SETTLED on CANCELLED order marks PENDING_RECONCILE, never PAID
        $cancelledOrder = Order::create([
            'user_id' => $user->id,
            'code' => 'SC-261125-000202',
            'status' => OrderStatus::CANCELLED,
            'payment_method' => PaymentMethod::STUDENT_CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'total_amount' => 25000,
            'discount_amount' => 0,
            'idempotency_key' => 'wh-cancelled-order',
            'payload_hash' => hash('sha256', 'wh-cancelled-order'),
            'version' => 1,
        ]);
        $cancelledTx = PaymentTransaction::create([
            'order_id' => $cancelledOrder->id,
            'user_id' => $user->id,
            'provider' => 'sandbox_epay',
            'type' => 'CHARGE',
            'amount' => 25000,
            'status' => 'TIMEOUT',
            'provider_reference' => 'EPAY-SBX-CHG-CANCELLED',
        ]);

        $cancelledPayload = [
            'event_id' => 'evt-cancelled-001',
            'event_type' => 'CHARGE_SETTLED',
            'provider_reference' => 'EPAY-SBX-CHG-CANCELLED',
        ];
        $this->withHeaders([
            'X-Gateway-Timestamp' => (string) $ts,
            'X-Gateway-Signature' => $this->sign(json_encode($cancelledPayload, JSON_THROW_ON_ERROR), $ts, $secret),
        ])->postJson('/api/v1/webhooks/banking/sandbox', $cancelledPayload)->assertOk();

        $this->assertSame('SUCCEEDED', $cancelledTx->fresh()->status);
        $this->assertSame(PaymentStatus::PENDING_RECONCILE, $cancelledOrder->fresh()->payment_status);

        // 3. CHARGE_SETTLED must NOT flip an already-FAILED charge transaction
        $failedTx = PaymentTransaction::create([
            'user_id' => $user->id,
            'provider' => 'sandbox_epay',
            'type' => 'CHARGE',
            'amount' => 20000,
            'status' => 'FAILED',
            'failure_code' => 'BANK_DECLINED',
            'provider_reference' => 'EPAY-SBX-CHG-ALREADY-FAILED',
        ]);
        $flipPayload = [
            'event_id' => 'evt-flip-001',
            'event_type' => 'CHARGE_SETTLED',
            'provider_reference' => 'EPAY-SBX-CHG-ALREADY-FAILED',
        ];
        $this->withHeaders([
            'X-Gateway-Timestamp' => (string) $ts,
            'X-Gateway-Signature' => $this->sign(json_encode($flipPayload, JSON_THROW_ON_ERROR), $ts, $secret),
        ])->postJson('/api/v1/webhooks/banking/sandbox', $flipPayload)->assertOk();

        $this->assertSame('FAILED', $failedTx->fresh()->status);
    }
}
