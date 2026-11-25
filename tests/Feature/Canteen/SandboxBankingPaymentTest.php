<?php

namespace Tests\Feature\Canteen;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SandboxBankMode;
use App\Enums\StudentCardStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\SandboxBankAccount;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SandboxBankingPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function setupLinkedSandboxCard(User $parent, string $accountNumber, int $dailyLimit = 200000): array
    {
        config()->set('services.banking.driver', 'sandbox');
        config()->set('services.banking.expose_debug_otp', true);

        $student = Student::create([
            'user_id' => $parent->id,
            'student_code' => 'HS-SBX-'.random_int(1000, 9999),
            'full_name' => 'Học sinh Sandbox',
            'class_name' => '8A1',
            'school_name' => 'THCS Trần Phú',
            'is_active' => true,
        ]);

        $card = StudentCard::create([
            'student_id' => $student->id,
            'card_code' => 'SBX'.random_int(100, 999),
            'status' => StudentCardStatus::ACTIVE,
            'issued_at' => now(),
        ]);

        Sanctum::actingAs($parent);

        $init = $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", [
            'bank_code' => 'MB',
            'account_number' => $accountNumber,
            'account_holder_name' => 'PHU HUYNH SANDBOX',
            'national_id' => '079090001234',
            'phone_number' => '0901234567',
            'agreed_terms' => true,
        ])->assertCreated();

        $linkId = (int) $init->json('data.bank_link_id');
        $otp = (string) $init->json('data.otp.debug_otp');

        $this->postJson("/api/v1/canteen/bank-links/{$linkId}/verify-otp", [
            'otp_code' => $otp,
        ])->assertOk();

        $this->putJson("/api/v1/canteen/cards/{$card->id}/spending-limit", [
            'daily_limit' => $dailyLimit,
        ])->assertOk();

        $card->refresh()->load('activeBankLink');
        $sandboxAccount = SandboxBankAccount::query()
            ->where('provider_reference', $card->activeBankLink->provider_reference)
            ->firstOrFail();

        return [$card, $sandboxAccount];
    }

    private function createProduct(int $price = 45000): Product
    {
        $category = Category::firstOrCreate(['name' => 'Cơm Sandbox'], ['is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Cơm trưa '.$price,
            'price' => $price,
            'is_available' => true,
        ]);
    }

    public function test_sandbox_charge_deducts_balance_records_ledger_and_refunds_on_cancel(): void
    {
        $parent = User::factory()->create();
        $staff = User::factory()->create(['role' => UserRole::STAFF]);
        [$card, $sandboxAccount] = $this->setupLinkedSandboxCard($parent, '0987654321111');
        $product = $this->createProduct(45000);

        $this->assertSame(500000, $sandboxAccount->balance);

        Sanctum::actingAs($parent);
        $orderRes = $this->withHeader('Idempotency-Key', 'sbx-charge-1')
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'student_card_id' => $card->id,
                'payment_method' => PaymentMethod::STUDENT_CARD->value,
            ])->assertCreated()
            ->assertJsonPath('data.payment_status', PaymentStatus::PAID->value);

        $orderId = (int) $orderRes->json('data.id');
        $this->assertSame(455000, $sandboxAccount->fresh()->balance);

        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $orderId,
            'provider' => 'sandbox_epay',
            'type' => 'CHARGE',
            'amount' => 45000,
            'status' => 'SUCCEEDED',
        ]);

        // Cancel order -> restores sandbox balance AND writes REFUND ledger record
        Sanctum::actingAs($staff);
        $this->patchJson("/api/v1/admin/orders/{$orderId}/status", [
            'status' => OrderStatus::CANCELLED->value,
            'version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::REFUNDED->value);

        $this->assertSame(500000, $sandboxAccount->fresh()->balance);
        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $orderId,
            'provider' => 'sandbox_epay',
            'type' => 'REFUND',
            'amount' => 45000,
            'status' => 'SUCCEEDED',
        ]);
    }

    public function test_insufficient_bank_balance_rolls_back_limit_and_persists_failed_ledger_row(): void
    {
        $parent = User::factory()->create();
        // Suffix 0000 initializes sandbox account with 0 VND balance
        [$card] = $this->setupLinkedSandboxCard($parent, '0987654320000');
        $product = $this->createProduct(30000);

        Sanctum::actingAs($parent);
        $this->withHeader('Idempotency-Key', 'sbx-insufficient-1')
            ->postJson("/api/v1/canteen/cards/{$card->id}/pos-purchases", [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertStatus(422)
            ->assertJsonPath('error_code', 'INSUFFICIENT_BANK_BALANCE');

        // Spending limit was NOT deducted (transaction rolled back cleanly)
        $this->assertSame(0, (int) $card->spendingLimit()->first()->spent_today);

        // Failed attempt IS durably recorded in payment_transactions
        $tx = PaymentTransaction::query()->latest('id')->first();
        $this->assertNotNull($tx);
        $this->assertNull($tx->order_id);
        $this->assertSame('FAILED', $tx->status);
        $this->assertSame('INSUFFICIENT_BANK_BALANCE', $tx->failure_code);
        $this->assertSame(30000, $tx->amount);
    }

    public function test_bank_declined_and_gateway_timeout_return_502_504_and_record_ledger(): void
    {
        $parent = User::factory()->create();
        [$card, $sandboxAccount] = $this->setupLinkedSandboxCard($parent, '0987654328888');
        $product = $this->createProduct(20000);

        Sanctum::actingAs($parent);
        $this->withHeader('Idempotency-Key', 'sbx-declined-1')
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'student_card_id' => $card->id,
                'payment_method' => PaymentMethod::STUDENT_CARD->value,
            ])->assertStatus(502)
            ->assertJsonPath('error_code', 'BANK_DECLINED');

        $this->assertDatabaseHas('payment_transactions', [
            'student_card_id' => $card->id,
            'status' => 'FAILED',
            'failure_code' => 'BANK_DECLINED',
        ]);

        // Switch sandbox account to GATEWAY_TIMEOUT
        $sandboxAccount->update(['simulated_mode' => SandboxBankMode::GATEWAY_TIMEOUT]);

        $this->withHeader('Idempotency-Key', 'sbx-timeout-1')
            ->postJson("/api/v1/canteen/cards/{$card->id}/pos-purchases", [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertStatus(504)
            ->assertJsonPath('error_code', 'GATEWAY_TIMEOUT');

        $this->assertDatabaseHas('payment_transactions', [
            'student_card_id' => $card->id,
            'status' => 'TIMEOUT',
            'failure_code' => 'GATEWAY_TIMEOUT',
        ]);
        $this->assertSame(0, (int) $card->spendingLimit()->first()->spent_today);
    }
}
