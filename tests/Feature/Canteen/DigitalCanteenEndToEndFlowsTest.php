<?php

namespace Tests\Feature\Canteen;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StudentCardStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end verification of all 4 core Digital Canteen business flows (FEATURES.md Section 9):
 * 1. Bank Account Linking Flow (CARD-01..07, BANK-01..16, OTP-01..07)
 * 2. Spending Limit Flow (LIMIT-01..13)
 * 3. Order & Transaction Tracking Flow + Admin Cancel/Refund (ORDER-01..07, DETAIL-01..14)
 * 4. Bank Account Unlinking Flow + Re-link preserving spent_today (UNLINK-01..07, OTP-08)
 */
class DigitalCanteenEndToEndFlowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_four_digital_canteen_business_flows_end_to_end(): void
    {
        config()->set('services.banking.expose_debug_otp', true);

        $parent = User::factory()->create(['name' => 'Phụ huynh E2E', 'role' => UserRole::CUSTOMER]);
        $staff = User::factory()->create(['name' => 'Nhân viên Căn tin', 'role' => UserRole::STAFF]);

        $student = Student::create([
            'user_id' => $parent->id,
            'student_code' => 'HS-E2E-001',
            'full_name' => 'Titkul Student',
            'class_name' => '8A2',
            'school_name' => 'THCS Trần Phú',
            'is_active' => true,
        ]);

        $card = StudentCard::create([
            'student_id' => $student->id,
            'card_code' => 'F156BA',
            'status' => StudentCardStatus::ACTIVE,
            'issued_at' => now()->subDays(1),
        ]);

        $category = Category::create(['name' => 'Nước giải khát', 'sort_order' => 1]);
        $drink = Product::create([
            'category_id' => $category->id,
            'name' => 'C2 chanh 500ml',
            'price' => 10000,
            'is_available' => true,
        ]);
        $lunch = Product::create([
            'category_id' => $category->id,
            'name' => 'Cơm trưa căn tin',
            'price' => 25000,
            'is_available' => true,
        ]);

        // =========================================================================
        // FLOW 1: Bank Account Linking (CARD-01..07, BANK-01..16, OTP-01..07)
        // =========================================================================
        Sanctum::actingAs($parent);

        $this->getJson('/api/v1/canteen/cards')
            ->assertOk()
            ->assertJsonPath('data.0.card_code', 'F156BA')
            ->assertJsonPath('data.0.is_bank_linked', false);

        $this->getJson('/api/v1/canteen/banks')
            ->assertOk()
            ->assertJsonFragment(['code' => 'MB']);

        $linkInit = $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", [
            'bank_code' => 'MB',
            'account_number' => '0987654321000',
            'account_holder_name' => 'Nguyen Van Phu Huynh',
            'national_id' => '079090001122',
            'phone_number' => '0901234567',
            'email' => 'phuhuynh@titkul.vn',
            'agreed_terms' => true,
        ])->assertCreated();

        $bankLinkId = (int) $linkInit->json('data.bank_link_id');
        $linkOtp = (string) $linkInit->json('data.otp.debug_otp');

        $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/verify-otp", [
            'otp_code' => $linkOtp,
        ])->assertOk()
            ->assertJsonPath('data.is_bank_linked', true)
            ->assertJsonPath('data.bank_link.bank_code', 'MB')
            ->assertJsonPath('data.bank_link.account_number_masked', '****1000')
            ->assertJsonPath('data.spending_limit.daily_limit', 0);

        // =========================================================================
        // FLOW 2: Spending Limit Management (LIMIT-01..13)
        // =========================================================================
        $this->putJson("/api/v1/canteen/cards/{$card->id}/spending-limit", [
            'daily_limit' => 50000,
            'reason' => 'Thiết lập ban đầu',
        ])->assertOk()
            ->assertJsonPath('data.spending_limit.daily_limit', 50000)
            ->assertJsonPath('data.spending_limit.remaining_limit', 50000);

        $this->putJson("/api/v1/canteen/cards/{$card->id}/spending-limit", [
            'daily_limit' => 110000,
            'reason' => 'Tăng hạn mức ngày học cả buổi',
        ])->assertOk()
            ->assertJsonPath('data.spending_limit.daily_limit', 110000)
            ->assertJsonPath('data.spending_limit.remaining_limit', 110000);

        $this->getJson("/api/v1/canteen/cards/{$card->id}/limit-logs")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.previous_limit', 50000)
            ->assertJsonPath('data.0.new_limit', 110000);

        // =========================================================================
        // FLOW 3: Order & POS Payment + Full Detail + Cancel/Refund (ORDER-01..07, DETAIL-01..14)
        // =========================================================================
        $posRes = $this->withHeader('Idempotency-Key', 'e2e-pos-1')
            ->postJson("/api/v1/canteen/cards/{$card->id}/pos-purchases", [
                'items' => [['product_id' => $drink->id, 'quantity' => 1]],
                'store_name' => 'THCS Trần Phú',
            ])->assertCreated()
            ->assertJsonPath('data.payment_method', PaymentMethod::STUDENT_CARD_POS->value)
            ->assertJsonPath('data.payment_status', PaymentStatus::PAID->value)
            ->assertJsonPath('data.total_amount', 10000)
            ->assertJsonPath('data.remaining_limit_snapshot', 100000);

        $posOrderId = (int) $posRes->json('data.id');
        $this->assertStringStartsWith('POS-', (string) $posRes->json('data.code'));

        // Cart order paid with student card (25,000đ)
        $cartOrderRes = $this->withHeader('Idempotency-Key', 'e2e-cart-1')
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $lunch->id, 'quantity' => 1]],
                'student_card_id' => $card->id,
                'payment_method' => PaymentMethod::STUDENT_CARD->value,
            ])->assertCreated()
            ->assertJsonPath('data.remaining_limit_snapshot', 75000);

        $cartOrderId = (int) $cartOrderRes->json('data.id');

        // Verify card orders list & full 14-field order detail
        $this->getJson("/api/v1/canteen/cards/{$card->id}/orders")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson("/api/v1/orders/{$posOrderId}")
            ->assertOk()
            ->assertJsonPath('data.student_name', 'Titkul Student')
            ->assertJsonPath('data.student_class', '8A2')
            ->assertJsonPath('data.card_code', 'F156BA')
            ->assertJsonPath('data.store_name', 'THCS Trần Phú')
            ->assertJsonPath('data.subtotal_amount', 10000)
            ->assertJsonPath('data.discount_amount', 0)
            ->assertJsonPath('data.total_amount', 10000)
            ->assertJsonPath('data.remaining_limit_snapshot', 100000);

        // Staff cancels cart order via Admin API -> refunds 25,000đ back to today's remaining limit (75k -> 100k)
        Sanctum::actingAs($staff);
        $this->patchJson("/api/v1/admin/orders/{$cartOrderId}/status", [
            'status' => OrderStatus::CANCELLED->value,
            'version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.status', OrderStatus::CANCELLED->value)
            ->assertJsonPath('data.payment_status', PaymentStatus::REFUNDED->value);

        // =========================================================================
        // FLOW 4: Bank Account Unlinking via OTP (UNLINK-01..07, OTP-08) & Re-link
        // =========================================================================
        Sanctum::actingAs($parent);

        $unlinkInit = $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/unlink/initiate")
            ->assertCreated();
        $unlinkOtp = (string) $unlinkInit->json('data.otp.debug_otp');

        $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/unlink/verify-otp", [
            'otp_code' => $unlinkOtp,
        ])->assertOk()
            ->assertJsonPath('data.is_bank_linked', false)
            ->assertJsonPath('data.bank_link', null)
            ->assertJsonPath('data.spending_limit.spent_today', 10000)
            ->assertJsonPath('data.spending_limit.remaining_limit', 100000);

        // Purchasing while unlinked is rejected
        $this->withHeader('Idempotency-Key', 'e2e-pos-after-unlink')
            ->postJson("/api/v1/canteen/cards/{$card->id}/pos-purchases", [
                'items' => [['product_id' => $drink->id, 'quantity' => 1]],
            ])->assertStatus(422)
            ->assertJsonPath('error_code', 'CARD_NOT_BANK_LINKED');

        // Re-link with a different bank (VCB) works seamlessly and preserves today's 10,000đ spent_today
        $relinkInit = $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", [
            'bank_code' => 'VCB',
            'account_number' => '1122334455667',
            'account_holder_name' => 'Nguyen Van Phu Huynh',
            'national_id' => '079090001122',
            'phone_number' => '0901234567',
            'agreed_terms' => true,
        ])->assertCreated();

        $relinkId = (int) $relinkInit->json('data.bank_link_id');
        $relinkOtp = (string) $relinkInit->json('data.otp.debug_otp');

        $this->postJson("/api/v1/canteen/bank-links/{$relinkId}/verify-otp", [
            'otp_code' => $relinkOtp,
        ])->assertOk()
            ->assertJsonPath('data.is_bank_linked', true)
            ->assertJsonPath('data.bank_link.bank_code', 'VCB')
            ->assertJsonPath('data.spending_limit.spent_today', 10000)
            ->assertJsonPath('data.spending_limit.remaining_limit', 100000);
    }
}
