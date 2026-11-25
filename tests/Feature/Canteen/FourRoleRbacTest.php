<?php

namespace Tests\Feature\Canteen;

use App\Enums\BankLinkStatus;
use App\Enums\PaymentMethod;
use App\Enums\SandboxBankMode;
use App\Enums\StudentCardStatus;
use App\Enums\UserRole;
use App\Models\BankLink;
use App\Models\Category;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\SandboxBankAccount;
use App\Models\SpendingLimit;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use App\Services\SpendingLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FourRoleRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_role_can_view_own_card_and_purchase_within_limit_but_cannot_manage_bank_or_limit(): void
    {
        $parent = User::factory()->create(['role' => UserRole::PARENT, 'phone' => '0901112222']);
        $studentUser = User::factory()->create(['role' => UserRole::STUDENT, 'phone' => '0901113333']);
        $otherStudentUser = User::factory()->create(['role' => UserRole::STUDENT, 'phone' => '0901114444']);

        $student = Student::create([
            'user_id' => $parent->id,
            'student_user_id' => $studentUser->id,
            'student_code' => 'HS-RBAC-01',
            'full_name' => 'Học sinh RBAC',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '8A3',
            'is_active' => true,
        ]);
        $card = StudentCard::create([
            'student_id' => $student->id,
            'card_code' => 'RBAC01',
            'status' => StudentCardStatus::ACTIVE,
            'issued_at' => now(),
        ]);
        $bankRef = 'EPAY-SBX-RBAC-1111';
        $bankLink = BankLink::create([
            'student_card_id' => $card->id,
            'user_id' => $parent->id,
            'bank_code' => 'MB',
            'bank_name' => 'MB Bank',
            'account_number_encrypted' => '0987654321111',
            'account_number_masked' => '****1111',
            'account_holder_name' => 'PHU HUYNH RBAC',
            'national_id_encrypted' => '079090001234',
            'national_id_masked' => '********1234',
            'phone_number' => '0901112222',
            'phone_masked' => '090***2222',
            'auto_debit_agreed' => true,
            'auto_debit_agreed_at' => now(),
            'provider_reference' => $bankRef,
            'status' => BankLinkStatus::ACTIVE,
            'linked_at' => now(),
        ]);
        SandboxBankAccount::create([
            'provider_reference' => $bankRef,
            'bank_code' => 'MB',
            'account_number_masked' => '****1111',
            'balance' => 300000,
            'simulated_mode' => SandboxBankMode::NORMAL,
        ]);
        SpendingLimit::create([
            'student_card_id' => $card->id,
            'daily_limit' => 50000,
            'spent_today' => 0,
            'spent_date' => now(SpendingLimitService::TIMEZONE)->toDateString(),
        ]);

        $category = Category::create(['name' => 'RBAC Menu', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Trà sữa trân châu',
            'price' => 20000,
            'is_available' => true,
            'stock_quantity' => 10,
        ]);

        // 1. /auth/me exposes effective_role & can_manage_canteen
        Sanctum::actingAs($studentUser);
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'student')
            ->assertJsonPath('data.effective_role', 'student')
            ->assertJsonPath('data.can_manage_canteen', false);

        // 2. Student can view own card, limit logs, card orders
        $this->getJson('/api/v1/canteen/cards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.card_code', 'RBAC01');
        $this->getJson('/api/v1/canteen/cards/'.$card->id)->assertOk();
        $this->getJson('/api/v1/canteen/cards/'.$card->id.'/limit-logs')->assertOk();
        $this->getJson('/api/v1/canteen/cards/'.$card->id.'/orders')->assertOk();

        // 3. Student is strictly blocked (403 PARENT_ROLE_REQUIRED) from bank linking, unlinking, and limit changes
        $this->putJson('/api/v1/canteen/cards/'.$card->id.'/spending-limit', ['daily_limit' => 500000])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'PARENT_ROLE_REQUIRED');

        $this->postJson('/api/v1/canteen/cards/'.$card->id.'/bank-links', [
            'bank_code' => 'MB',
            'account_number' => '1234567890',
            'account_holder_name' => 'TEST',
            'national_id' => '079090001234',
            'phone_number' => '0901113333',
            'auto_debit_agreed' => true,
        ])->assertForbidden()->assertJsonPath('error_code', 'PARENT_ROLE_REQUIRED');

        $this->postJson('/api/v1/canteen/bank-links/'.$bankLink->id.'/unlink/initiate')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'PARENT_ROLE_REQUIRED');

        // 4. Student can place an order using their own card within parent's daily limit
        $orderRes = $this->withHeader('Idempotency-Key', 'student-order-1')
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => PaymentMethod::STUDENT_CARD->value,
                'student_card_id' => $card->id,
            ])
            ->assertCreated();
        $orderId = (int) $orderRes->json('data.id');

        // 5. Parent can view the order placed by their child on their child's card, and CAN update spending limit
        Sanctum::actingAs($parent);
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.effective_role', 'parent')
            ->assertJsonPath('data.can_manage_canteen', true);
        $this->getJson('/api/v1/orders/'.$orderId)->assertOk();
        $this->putJson('/api/v1/canteen/cards/'.$card->id.'/spending-limit', ['daily_limit' => 80000])
            ->assertOk();

        // 6. Another student cannot see this card or order (IDOR 404)
        Sanctum::actingAs($otherStudentUser);
        $this->getJson('/api/v1/canteen/cards')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/canteen/cards/'.$card->id)->assertNotFound();
        $this->getJson('/api/v1/orders/'.$orderId)->assertNotFound();

        // 7. loginDemo & loginZalo preserve existing STUDENT role (never overwrite to CUSTOMER)
        config()->set('services.demo.login_enabled', true);
        $demoStudent = User::factory()->create([
            'zalo_user_id' => 'demo_student_keep',
            'role' => UserRole::STUDENT,
            'name' => 'Student Role Preserved',
        ]);
        $this->postJson('/api/v1/auth/demo', ['demo_key' => 'student_keep'])
            ->assertOk()
            ->assertJsonPath('data.user.role', UserRole::STUDENT->value)
            ->assertJsonPath('data.user.can_manage_canteen', false);
        $this->assertSame(UserRole::STUDENT, $demoStudent->fresh()->role);
    }

    public function test_timeout_charge_persists_deterministic_gateway_txn_ref_for_reconciliation(): void
    {
        $parent = User::factory()->create(['role' => UserRole::CUSTOMER]);
        $student = Student::create([
            'user_id' => $parent->id,
            'student_code' => 'HS-TMO-01',
            'full_name' => 'Học sinh Timeout',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '8A9',
            'is_active' => true,
        ]);
        $card = StudentCard::create([
            'student_id' => $student->id,
            'card_code' => 'TMO999',
            'status' => StudentCardStatus::ACTIVE,
            'issued_at' => now(),
        ]);
        $bankRef = 'EPAY-SBX-TMO-9999';
        BankLink::create([
            'student_card_id' => $card->id,
            'user_id' => $parent->id,
            'bank_code' => 'MB',
            'bank_name' => 'MB Bank',
            'account_number_encrypted' => '0987654329999',
            'account_number_masked' => '****9999',
            'account_holder_name' => 'PHU HUYNH TMO',
            'national_id_encrypted' => '079090001234',
            'national_id_masked' => '********1234',
            'phone_number' => '0901234567',
            'phone_masked' => '090***4567',
            'auto_debit_agreed' => true,
            'auto_debit_agreed_at' => now(),
            'provider_reference' => $bankRef,
            'status' => BankLinkStatus::ACTIVE,
            'linked_at' => now(),
        ]);
        SandboxBankAccount::create([
            'provider_reference' => $bankRef,
            'bank_code' => 'MB',
            'account_number_masked' => '****9999',
            'balance' => 500000,
            'simulated_mode' => SandboxBankMode::GATEWAY_TIMEOUT,
        ]);
        SpendingLimit::create([
            'student_card_id' => $card->id,
            'daily_limit' => 100000,
            'spent_today' => 0,
            'spent_date' => now(SpendingLimitService::TIMEZONE)->toDateString(),
        ]);

        $category = Category::create(['name' => 'Timeout Cat', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mì xào',
            'price' => 30000,
            'is_available' => true,
            'stock_quantity' => 5,
        ]);

        Sanctum::actingAs($parent);
        $this->withHeader('Idempotency-Key', 'tmo-order-ref-1')
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => PaymentMethod::STUDENT_CARD->value,
                'student_card_id' => $card->id,
            ])
            ->assertStatus(504)
            ->assertJsonPath('error_code', 'GATEWAY_TIMEOUT');

        // Timeout transaction persisted with non-null gateway_txn_ref and stock rolled back
        $tx = PaymentTransaction::where('idempotency_key', 'tmo-order-ref-1')->firstOrFail();
        $this->assertSame('TIMEOUT', $tx->status);
        $this->assertNotNull($tx->gateway_txn_ref);
        $this->assertStringStartsWith('CHG-SC-', (string) $tx->gateway_txn_ref);
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }
}
