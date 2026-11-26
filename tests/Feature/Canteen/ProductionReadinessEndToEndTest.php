<?php

namespace Tests\Feature\Canteen;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SandboxBankMode;
use App\Enums\UserRole;
use App\Models\CanteenNotification;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SandboxBankAccount;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use App\Services\Payment\VnpayGatewayService;
use App\Services\ReconciliationService;
use App\Services\SpendingLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductionReadinessEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_truc3_production_readiness_e2e_workflow_p1_to_p4(): void
    {
        config()->set('services.demo.login_enabled', true);
        config()->set('services.banking.driver', 'sandbox');
        config()->set('services.banking.expose_debug_otp', true);
        config()->set('services.sms.driver', 'log');
        config()->set('services.notifications.push_driver', 'log');
        config()->set('services.vnpay.tmn_code', 'E2E_TMN');
        config()->set('services.vnpay.hash_secret', 'E2E_VNPAY_SECRET_512');

        $admin = User::factory()->create(['role' => UserRole::ADMIN, 'name' => 'Admin E2E']);
        $staff = User::factory()->create(['role' => UserRole::STAFF, 'name' => 'Staff POS E2E']);
        $parent = User::factory()->create([
            'role' => UserRole::PARENT,
            'name' => 'Phụ huynh E2E',
            'phone' => '0901234567',
            'phone_verified_at' => now(),
        ]);
        $studentUser = User::factory()->create([
            'role' => UserRole::STUDENT,
            'name' => 'Học sinh E2E',
            'phone' => '0901234568',
            'phone_verified_at' => now(),
        ]);

        // 1. P3 Admin Student CRUD + Card Issuance
        $this->actingAs($admin)->post(route('admin.canteen.students.store'), [
            'user_id' => $parent->id,
            'student_user_id' => $studentUser->id,
            'student_code' => 'HS-E2E-001',
            'full_name' => 'Lê Minh Khôi',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '8A1',
            'is_active' => '1',
            'issue_initial_card' => '1',
            'initial_card_code' => 'E2E100',
        ])->assertRedirect(route('admin.canteen.students.index'));

        $card = StudentCard::where('card_code', 'E2E100')->firstOrFail();

        // 2. P1 Demo Phone Login preserves roles for Parent & Student
        $this->postJson('/api/v1/auth/demo-phone', ['phone_number' => '0901234567'])
            ->assertOk()
            ->assertJsonPath('data.user.effective_role', 'parent')
            ->assertJsonPath('data.user.can_manage_canteen', true);

        $this->postJson('/api/v1/auth/demo-phone', ['phone_number' => '0901234568'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'student')
            ->assertJsonPath('data.user.can_manage_canteen', false);

        // 3. P1+P2 Bank Link + SMS OTP -> P4 BANK_LINK_CHANGED notification
        Sanctum::actingAs($parent);
        $initRes = $this->postJson('/api/v1/canteen/cards/'.$card->id.'/bank-links', [
            'bank_code' => 'MB',
            'account_number' => '0987654321111',
            'account_holder_name' => 'PHU HUYNH E2E',
            'national_id' => '079090001234',
            'phone_number' => '0901234567',
            'agreed_terms' => true,
        ])->assertCreated();

        $bankLinkId = (int) $initRes->json('data.bank_link_id');
        $debugOtp = (string) $initRes->json('data.otp.debug_otp');
        $this->assertNotSame('', $debugOtp);

        $this->postJson('/api/v1/canteen/bank-links/'.$bankLinkId.'/verify-otp', [
            'otp_code' => $debugOtp,
        ])->assertOk();

        $this->assertDatabaseHas('canteen_notifications', [
            'user_id' => $parent->id,
            'type' => NotificationType::BANK_LINK_CHANGED->value,
        ]);

        // 4. Set Spending Limit 60,000 VND -> P4 SPENDING_LIMIT_UPDATED notification
        $this->putJson('/api/v1/canteen/cards/'.$card->id.'/spending-limit', [
            'daily_limit' => 60000,
            'reason' => 'Hạn mức ăn trưa',
        ])->assertOk();

        $this->assertDatabaseHas('canteen_notifications', [
            'user_id' => $parent->id,
            'type' => NotificationType::SPENDING_LIMIT_UPDATED->value,
        ]);

        // 5. P3 Tracked Inventory + Staff POS Terminal checkout (50,000 <= 60,000 -> remaining 10,000 <= 20%)
        $category = Category::create(['name' => 'E2E Menu', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Cơm bò lúc lắc',
            'price' => 50000,
            'is_available' => true,
            'stock_quantity' => 4,
            'low_stock_threshold' => 2,
        ]);

        $posRes = $this->actingAs($staff)
            ->postJson(route('admin.pos.checkout'), [
                'payment_method' => PaymentMethod::STUDENT_CARD_POS->value,
                'student_card_id' => $card->id,
                'idempotency_key' => 'e2e-pos-sale-001',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.remaining_limit_snapshot', 10000);

        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('canteen_notifications', [
            'user_id' => $parent->id,
            'type' => NotificationType::PURCHASE_SUCCESS->value,
        ]);
        $this->assertDatabaseHas('canteen_notifications', [
            'user_id' => $parent->id,
            'type' => NotificationType::LOW_SPENDING_LIMIT->value,
        ]);

        // 6. P3 Student RBAC: cannot change spending limit (403 PARENT_ROLE_REQUIRED)
        Sanctum::actingAs($studentUser);
        $this->putJson('/api/v1/canteen/cards/'.$card->id.'/spending-limit', ['daily_limit' => 500000])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'PARENT_ROLE_REQUIRED');

        // 7. P2 Insufficient Bank Balance -> 422 + stock rolled back + P4 PAYMENT_FAILED notification
        $activeLink = $card->activeBankLink()->firstOrFail();
        SandboxBankAccount::where('provider_reference', $activeLink->provider_reference)
            ->update(['balance' => 0, 'simulated_mode' => SandboxBankMode::INSUFFICIENT_FUNDS]);

        Sanctum::actingAs($parent);
        $this->putJson('/api/v1/canteen/cards/'.$card->id.'/spending-limit', ['daily_limit' => 200000])->assertOk();

        $this->withHeader('Idempotency-Key', 'e2e-insufficient-1')
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => PaymentMethod::STUDENT_CARD->value,
                'student_card_id' => $card->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'INSUFFICIENT_BANK_BALANCE');

        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('canteen_notifications', [
            'user_id' => $parent->id,
            'type' => NotificationType::PAYMENT_FAILED->value,
        ]);

        // 8. P2 VNPay Sandbox order -> IPN success -> Reconciliation matches cleanly
        $vnpOrderRes = $this->withHeader('Idempotency-Key', 'e2e-vnpay-1')
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => PaymentMethod::VNPAY->value,
            ])
            ->assertCreated();
        $vnpOrder = Order::findOrFail((int) $vnpOrderRes->json('data.id'));

        $vnpCreate = $this->postJson('/api/v1/payments/vnpay/create-url', ['order_id' => $vnpOrder->id])
            ->assertCreated();
        $txnRef = (string) $vnpCreate->json('data.txn_ref');

        $vnpay = app(VnpayGatewayService::class);
        $ipn = [
            'vnp_TmnCode' => 'E2E_TMN',
            'vnp_Amount' => '5000000',
            'vnp_TxnRef' => $txnRef,
            'vnp_ResponseCode' => '00',
            'vnp_TransactionStatus' => '00',
            'vnp_TransactionNo' => 'E2E-VNP-888',
        ];
        $ipn['vnp_SecureHash'] = $vnpay->signParams($ipn, 'E2E_VNPAY_SECRET_512');
        $this->getJson('/api/v1/payments/vnpay/ipn?'.http_build_query($ipn))
            ->assertOk()
            ->assertJson(['RspCode' => '00']);
        $this->assertSame(PaymentStatus::PAID, $vnpOrder->fresh()->payment_status);

        $todayVn = now(SpendingLimitService::TIMEZONE)->toDateString();
        $sbxBatch = app(ReconciliationService::class)->runForDate($todayVn, 'sandbox_epay', $admin);
        $vnpBatch = app(ReconciliationService::class)->runForDate($todayVn, 'vnpay', $admin);
        $this->assertSame('COMPLETED', $sbxBatch->status);
        $this->assertSame(1, $sbxBatch->matched_count);
        $this->assertSame('COMPLETED', $vnpBatch->status);
        $this->assertSame(1, $vnpBatch->matched_count);

        // 9. P3 Order Cancel restores tracked stock
        $this->actingAs($staff)->patch(route('admin.orders.status', $vnpOrder), [
            'status' => OrderStatus::CANCELLED->value,
            'version' => 1,
        ])->assertRedirect();
        $this->assertSame(3, $product->fresh()->stock_quantity);

        // 10. PII Audit: no raw bank account or CCCD leaked in any stored notification
        $allNotifText = CanteenNotification::query()
            ->get()
            ->map(fn (CanteenNotification $n) => $n->title.' '.$n->body.' '.json_encode($n->meta))
            ->implode(' ');
        $this->assertStringNotContainsString('0987654321111', $allNotifText);
        $this->assertStringNotContainsString('079090001234', $allNotifText);
    }
}
