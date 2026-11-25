<?php

namespace Tests\Feature\Admin;

use App\Enums\BankLinkStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StudentCardStatus;
use App\Enums\UserRole;
use App\Models\BankLink;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SpendingLimit;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use App\Services\SpendingLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCanteenManagementTest extends TestCase
{
    use RefreshDatabase;

    private function createLinkedStudentCard(User $parent): array
    {
        $student = Student::create([
            'user_id' => $parent->id,
            'student_code' => 'HS-ADMIN-01',
            'full_name' => 'Trần Bảo An',
            'class_name' => '7A1',
            'school_name' => 'THCS Trần Phú',
            'is_active' => true,
        ]);

        $card = StudentCard::create([
            'student_id' => $student->id,
            'card_code' => 'ADM999',
            'status' => StudentCardStatus::ACTIVE,
            'issued_at' => now()->subDays(3),
        ]);

        $bankLink = BankLink::create([
            'student_card_id' => $card->id,
            'user_id' => $parent->id,
            'bank_code' => 'MB',
            'bank_name' => 'MB Bank',
            'account_number_encrypted' => '9988776655443',
            'account_number_masked' => '*********5443',
            'account_holder_name' => 'TRAN VAN PHU HUYNH',
            'national_id_encrypted' => '079099887766',
            'national_id_masked' => '********7766',
            'phone_number' => '0987654321',
            'phone_masked' => '098****321',
            'auto_debit_agreed' => true,
            'auto_debit_agreed_at' => now()->subDays(2),
            'provider_reference' => 'EPAY-ADMIN-001',
            'status' => BankLinkStatus::ACTIVE,
            'linked_at' => now()->subDays(2),
        ]);

        $today = app(SpendingLimitService::class)->todayDate();
        SpendingLimit::create([
            'student_card_id' => $card->id,
            'daily_limit' => 80000,
            'spent_today' => 20000,
            'spent_date' => $today,
        ]);

        return [$student, $card, $bankLink];
    }

    public function test_rbac_protects_admin_canteen_routes_and_never_exposes_plaintext_pii(): void
    {
        $parent = User::factory()->create(['role' => UserRole::CUSTOMER]);
        $staff = User::factory()->create(['role' => UserRole::STAFF]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        [, $card] = $this->createLinkedStudentCard($parent);

        // Guest redirects to admin login
        $this->get(route('admin.canteen.cards.index'))->assertRedirect(route('admin.login'));

        // Customer gets 403
        $this->actingAs($parent)->get(route('admin.canteen.cards.index'))->assertForbidden();

        // Staff can view index and show with masked PII only
        $this->actingAs($staff)
            ->get(route('admin.canteen.cards.index', ['search' => 'ADM999', 'link_status' => 'linked']))
            ->assertOk()
            ->assertSee('ADM999')
            ->assertSee('Trần Bảo An')
            ->assertSee('*********5443')
            ->assertDontSee('9988776655443')
            ->assertDontSee('079099887766');

        $this->actingAs($staff)
            ->get(route('admin.canteen.cards.show', $card))
            ->assertOk()
            ->assertSee('ADM999')
            ->assertSee('*********5443')
            ->assertSee('********7766')
            ->assertSee('098****321')
            ->assertDontSee('9988776655443')
            ->assertDontSee('079099887766')
            ->assertDontSee('0987654321');

        // Staff cannot mutate card status or spending limit
        $this->actingAs($staff)
            ->patch(route('admin.canteen.cards.status', $card), ['status' => 'LOCKED'])
            ->assertForbidden();

        $this->actingAs($staff)
            ->put(route('admin.canteen.cards.limit', $card), ['daily_limit' => 50000])
            ->assertForbidden();

        // Admin can view dashboard with digital canteen summary
        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Căn tin số — Thanh toán thẻ học sinh hôm nay');
    }

    public function test_admin_can_update_spending_limit_and_card_status_with_revoked_auto_unlinking(): void
    {
        $parent = User::factory()->create(['role' => UserRole::CUSTOMER]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        [, $card, $bankLink] = $this->createLinkedStudentCard($parent);

        // Admin updates spending limit and records audit log
        $this->actingAs($admin)
            ->put(route('admin.canteen.cards.limit', $card), [
                'daily_limit' => 120000,
                'reason' => 'Hỗ trợ phụ huynh qua hotline',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('spending_limits', [
            'student_card_id' => $card->id,
            'daily_limit' => 120000,
            'spent_today' => 20000,
        ]);

        $this->assertDatabaseHas('spending_limit_logs', [
            'student_card_id' => $card->id,
            'changed_by' => $admin->id,
            'previous_limit' => 80000,
            'new_limit' => 120000,
            'reason' => 'Hỗ trợ phụ huynh qua hotline',
        ]);

        // Admin locks card, then unlocks card
        $this->actingAs($admin)
            ->patch(route('admin.canteen.cards.status', $card), ['status' => 'LOCKED'])
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertSame(StudentCardStatus::LOCKED, $card->fresh()->status);

        $this->actingAs($admin)
            ->patch(route('admin.canteen.cards.status', $card), ['status' => 'ACTIVE'])
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertSame(StudentCardStatus::ACTIVE, $card->fresh()->status);

        // Admin revokes card -> auto unlinks active bank link
        $this->actingAs($admin)
            ->patch(route('admin.canteen.cards.status', $card), ['status' => 'REVOKED'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(StudentCardStatus::REVOKED, $card->fresh()->status);
        $this->assertSame(BankLinkStatus::UNLINKED, $bankLink->fresh()->status);
        $this->assertNotNull($bankLink->fresh()->unlinked_at);

        // Revoked card cannot be reactivated
        $this->actingAs($admin)
            ->patch(route('admin.canteen.cards.status', $card), ['status' => 'ACTIVE'])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertSame(StudentCardStatus::REVOKED, $card->fresh()->status);
    }

    public function test_admin_orders_views_and_api_display_student_card_payment_details(): void
    {
        $parent = User::factory()->create(['role' => UserRole::CUSTOMER]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        [, $card] = $this->createLinkedStudentCard($parent);

        $category = Category::create(['name' => 'Đồ uống', 'sort_order' => 1]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'C2 chanh 500ml',
            'price' => 10000,
            'is_available' => true,
        ]);

        $order = Order::create([
            'code' => 'POS-261125-000001',
            'user_id' => $parent->id,
            'student_card_id' => $card->id,
            'payment_method' => PaymentMethod::STUDENT_CARD_POS,
            'payment_status' => PaymentStatus::PAID,
            'store_name' => 'THCS Trần Phú',
            'discount_amount' => 0,
            'total_amount' => 10000,
            'remaining_limit_snapshot' => 60000,
            'payment_reference' => 'EPAY-CHG-TEST-1',
            'status' => OrderStatus::COMPLETED,
            'idempotency_key' => 'admin-order-view-test-1',
            'payload_hash' => hash('sha256', 'admin-order-view-test-1'),
            'version' => 1,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 10000,
            'subtotal' => 10000,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('POS-261125-000001')
            ->assertSee('ADM999')
            ->assertSee('Trần Bảo An')
            ->assertSee('PAID');

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('ADM999')
            ->assertSee('THCS Trần Phú')
            ->assertSee('60.000 ₫');

        // Verify Slice D2 Minor #1: Admin API GET /api/v1/admin/orders includes studentCard.student fields
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonPath('data.0.card_code', 'ADM999')
            ->assertJsonPath('data.0.student_name', 'Trần Bảo An')
            ->assertJsonPath('data.0.student_class', '7A1');
    }
}
