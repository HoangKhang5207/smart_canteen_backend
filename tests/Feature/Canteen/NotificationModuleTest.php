<?php

namespace Tests\Feature\Canteen;

use App\Enums\BankLinkStatus;
use App\Enums\NotificationSeverity;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SandboxBankMode;
use App\Enums\StudentCardStatus;
use App\Enums\UserRole;
use App\Models\BankLink;
use App\Models\CanteenNotification;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SandboxBankAccount;
use App\Models\SpendingLimit;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\OrderService;
use App\Services\SpendingLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_low_limit_refund_and_notification_api_with_idor_protection(): void
    {
        $parent = User::factory()->create(['role' => UserRole::PARENT, 'phone' => '0903000111']);
        $studentUser = User::factory()->create(['role' => UserRole::STUDENT, 'phone' => '0903000222']);
        $stranger = User::factory()->create(['role' => UserRole::PARENT, 'phone' => '0903000333']);
        $staff = User::factory()->create(['role' => UserRole::STAFF]);

        $student = Student::create([
            'user_id' => $parent->id,
            'student_user_id' => $studentUser->id,
            'student_code' => 'HS-NTF-01',
            'full_name' => 'Học sinh Thông Báo',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '8A4',
            'is_active' => true,
        ]);
        $card = StudentCard::create([
            'student_id' => $student->id,
            'card_code' => 'NTF001',
            'status' => StudentCardStatus::ACTIVE,
            'issued_at' => now(),
        ]);
        $bankRef = 'EPAY-SBX-NTF-1111';
        BankLink::create([
            'student_card_id' => $card->id,
            'user_id' => $parent->id,
            'bank_code' => 'MB',
            'bank_name' => 'MB Bank',
            'account_number_encrypted' => '0987654321111',
            'account_number_masked' => '****1111',
            'account_holder_name' => 'PHU HUYNH NTF',
            'national_id_encrypted' => '079090001234',
            'national_id_masked' => '********1234',
            'phone_number' => '0903000111',
            'phone_masked' => '090***0111',
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

        $category = Category::create(['name' => 'Notif Cat', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Cơm sườn đặc biệt',
            'price' => 45000,
            'is_available' => true,
            'stock_quantity' => 5,
        ]);

        // Purchase 45,000 out of 50,000 -> remaining 5,000 (10% <= 20%) -> PURCHASE_SUCCESS + LOW_SPENDING_LIMIT
        Sanctum::actingAs($parent);
        $orderRes = $this->withHeader('Idempotency-Key', 'notif-purchase-1')
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => PaymentMethod::STUDENT_CARD->value,
                'student_card_id' => $card->id,
            ])
            ->assertCreated();

        // Security headers present on API response
        $orderRes->assertHeader('X-Content-Type-Options', 'nosniff');
        $orderRes->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $orderRes->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Parent receives PURCHASE_SUCCESS + LOW_SPENDING_LIMIT
        $listRes = $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 2);

        $types = collect($listRes->json('data'))->pluck('type')->all();
        $this->assertContains(NotificationType::PURCHASE_SUCCESS->value, $types);
        $this->assertContains(NotificationType::LOW_SPENDING_LIMIT->value, $types);

        // Student also receives PURCHASE_SUCCESS
        Sanctum::actingAs($studentUser);
        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        // Stranger gets 404 trying to mark parent's notification as read (IDOR)
        $parentNotifId = (int) $listRes->json('data.0.id');
        Sanctum::actingAs($stranger);
        $this->patchJson('/api/v1/notifications/'.$parentNotifId.'/read')->assertNotFound();

        // Parent marks 1 as read, then marks all as read
        Sanctum::actingAs($parent);
        $this->patchJson('/api/v1/notifications/'.$parentNotifId.'/read')
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        $this->getJson('/api/v1/notifications?unread_only=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_count', 1)
            ->assertJsonPath('data.unread_count', 0);

        // Cancel order -> ORDER_REFUNDED notification generated
        $order = Order::findOrFail((int) $orderRes->json('data.id'));
        app(OrderService::class)->transitionStatus($order, OrderStatus::CANCELLED, 1, $staff);

        $this->assertDatabaseHas('canteen_notifications', [
            'user_id' => $parent->id,
            'type' => NotificationType::ORDER_REFUNDED->value,
        ]);

        // Admin Blade notification log accessible to staff/admin, forbidden to parent/student
        $this->actingAs($staff)->get(route('admin.canteen.notifications.index'))->assertOk();
        $this->actingAs($parent)->get(route('admin.canteen.notifications.index'))->assertForbidden();
        $this->actingAs($studentUser)->get(route('admin.canteen.notifications.index'))->assertForbidden();
    }

    public function test_zalo_oa_and_zns_push_channels_and_resilience_on_provider_500(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::PARENT,
            'zalo_user_id' => 'zalo_real_uid_999',
            'phone' => '0905123456',
        ]);

        Http::fake([
            'https://openapi.zalo.me/*' => Http::sequence()
                ->push(['error' => 0, 'data' => ['message_id' => 'OA-MSG-001']], 200)
                ->push(['error' => 'invalid_token'], 200),
            'https://business.zalo.me/*' => Http::response(['error' => -124, 'message' => 'Internal error'], 500),
        ]);

        // 1. Zalo OA push succeeds
        config()->set('services.notifications.push_driver', 'zalo_oa');
        config()->set('services.notifications.zalo_oa.access_token', 'test-oa-token');

        $oaNotif = app(NotificationService::class)->send(
            recipient: $user,
            type: NotificationType::PURCHASE_SUCCESS,
            severity: NotificationSeverity::SUCCESS,
            title: 'Test OA',
            body: 'Body OA',
            actionUrl: '/orders/1',
            meta: ['account_number' => 'SHOULD_BE_STRIPPED', 'phone_number' => '0905123456', 'order_id' => 1],
        );

        $this->assertNotNull($oaNotif);
        $this->assertSame('zalo_oa', $oaNotif->push_channel);
        $this->assertSame('SENT', $oaNotif->push_status);
        $this->assertSame('OA-MSG-001', $oaNotif->push_reference);
        $this->assertArrayNotHasKey('account_number', $oaNotif->meta ?? []);
        $this->assertArrayNotHasKey('phone_number', $oaNotif->meta ?? []);

        // 2. Malformed non-numeric Zalo OA error must NOT falsely register as SENT
        $malformedOa = app(NotificationService::class)->send(
            recipient: $user,
            type: NotificationType::BANK_LINK_CHANGED,
            severity: NotificationSeverity::WARNING,
            title: 'Malformed OA',
            body: 'Body',
            actionUrl: '/\\evil.example/phish',
        );
        $this->assertNotNull($malformedOa);
        $this->assertSame('FAILED', $malformedOa->push_status);
        $this->assertNull($malformedOa->action_url);

        // 3. Zalo ZNS push fails with HTTP 500 -> In-App notification still persisted with push_status = FAILED
        config()->set('services.notifications.push_driver', 'zalo_zns');
        config()->set('services.notifications.zalo_zns.access_token', 'test-zns-token');
        config()->set('services.notifications.zalo_zns.template_id', 'tpl_12345');

        $znsNotif = app(NotificationService::class)->send(
            recipient: $user,
            type: NotificationType::PAYMENT_FAILED,
            severity: NotificationSeverity::ERROR,
            title: 'Test ZNS Failure',
            body: 'Push error must not fail business logic',
            actionUrl: 'https://evil.example/open-redirect',
        );

        $this->assertNotNull($znsNotif);
        $this->assertSame('zalo_zns', $znsNotif->push_channel);
        $this->assertSame('FAILED', $znsNotif->push_status);
        // Unsafe external actionUrl must be stripped
        $this->assertNull($znsNotif->action_url);
        $this->assertSame(3, CanteenNotification::where('user_id', $user->id)->count());
    }
}
