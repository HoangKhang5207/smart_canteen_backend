<?php

namespace Tests\Feature\Admin;

use App\Enums\BankLinkStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SandboxBankMode;
use App\Enums\StudentCardStatus;
use App\Enums\UserRole;
use App\Models\BankLink;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SandboxBankAccount;
use App\Models\SpendingLimit;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use App\Services\SpendingLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPosTerminalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{staff: User, parent: User, card: StudentCard, product: Product, sandbox: SandboxBankAccount}
     */
    private function seedPosFixture(int $dailyLimit = 100000, int $bankBalance = 500000, int $stock = 10): array
    {
        $staff = User::factory()->create(['role' => UserRole::STAFF, 'name' => 'Thu ngân POS']);
        $parent = User::factory()->create(['role' => UserRole::PARENT, 'name' => 'Phụ huynh POS']);
        $student = Student::create([
            'user_id' => $parent->id,
            'student_code' => 'HS-POS-001',
            'full_name' => 'Nguyễn POS',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '8A5',
            'is_active' => true,
        ]);
        $card = StudentCard::create([
            'student_id' => $student->id,
            'card_code' => 'POS88A',
            'status' => StudentCardStatus::ACTIVE,
            'issued_at' => now(),
        ]);
        $bankRef = 'EPAY-SBX-POS-1111';
        BankLink::create([
            'student_card_id' => $card->id,
            'user_id' => $parent->id,
            'bank_code' => 'MB',
            'bank_name' => 'MB Bank',
            'account_number_encrypted' => '0987654321111',
            'account_number_masked' => '****1111',
            'account_holder_name' => 'PHU HUYNH POS',
            'national_id_encrypted' => '079090009999',
            'national_id_masked' => '********9999',
            'phone_number' => '0901234567',
            'phone_masked' => '090***4567',
            'auto_debit_agreed' => true,
            'auto_debit_agreed_at' => now(),
            'provider_reference' => $bankRef,
            'status' => BankLinkStatus::ACTIVE,
            'linked_at' => now(),
        ]);
        $sandbox = SandboxBankAccount::create([
            'provider_reference' => $bankRef,
            'bank_code' => 'MB',
            'account_number_masked' => '****1111',
            'balance' => $bankBalance,
            'simulated_mode' => SandboxBankMode::NORMAL,
        ]);
        SpendingLimit::create([
            'student_card_id' => $card->id,
            'daily_limit' => $dailyLimit,
            'spent_today' => 0,
            'spent_date' => now(SpendingLimitService::TIMEZONE)->toDateString(),
        ]);

        $category = Category::create(['name' => 'POS Menu', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Cơm gà POS',
            'price' => 35000,
            'is_available' => true,
            'stock_quantity' => $stock,
            'low_stock_threshold' => 3,
        ]);

        return compact('staff', 'parent', 'card', 'product', 'sandbox');
    }

    public function test_staff_pos_lookup_checkout_idempotency_receipt_and_cash_sale(): void
    {
        ['staff' => $staff, 'parent' => $parent, 'card' => $card, 'product' => $product, 'sandbox' => $sandbox] = $this->seedPosFixture();

        // Parent & Student cannot access /admin/pos
        $studentUser = User::factory()->create(['role' => UserRole::STUDENT]);
        $this->actingAs($parent)->get(route('admin.pos.index'))->assertForbidden();
        $this->actingAs($studentUser)->get(route('admin.pos.index'))->assertForbidden();

        // Staff loads POS page & looks up card without leaking full account/CCCD
        $this->actingAs($staff)->get(route('admin.pos.index'))->assertOk();
        $lookupRes = $this->actingAs($staff)
            ->getJson(route('admin.pos.lookup', ['q' => 'pos88a']))
            ->assertOk()
            ->assertJsonPath('data.card_code', 'POS88A')
            ->assertJsonPath('data.student.full_name', 'Nguyễn POS')
            ->assertJsonPath('data.spending_limit.remaining_limit', 100000);

        $lookupBody = (string) $lookupRes->getContent();
        $this->assertStringNotContainsString('0987654321111', $lookupBody);
        $this->assertStringNotContainsString('079090009999', $lookupBody);

        // Staff performs student card POS checkout
        $payload = [
            'payment_method' => PaymentMethod::STUDENT_CARD_POS->value,
            'student_card_id' => $card->id,
            'idempotency_key' => 'staff-pos-idem-001',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ];

        $checkoutRes = $this->actingAs($staff)
            ->postJson(route('admin.pos.checkout'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.total_amount', 70000)
            ->assertJsonPath('data.remaining_limit_snapshot', 30000)
            ->assertJsonPath('data.payment_status', PaymentStatus::PAID->value);

        $orderId = (int) $checkoutRes->json('data.order_id');
        $order = Order::findOrFail($orderId);
        $this->assertStringStartsWith('POS-', $order->code);
        $this->assertSame($parent->id, $order->user_id);
        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertSame(430000, $sandbox->fresh()->balance);
        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order->id,
            'type' => 'CHARGE',
            'status' => 'SUCCEEDED',
            'amount' => 70000,
        ]);

        // Duplicate idempotency_key returns 200 without double charge or double stock deduction
        $this->actingAs($staff)
            ->postJson(route('admin.pos.checkout'), $payload)
            ->assertOk()
            ->assertJsonPath('data.order_id', $orderId)
            ->assertJsonPath('data.created', false);

        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertSame(430000, $sandbox->fresh()->balance);

        // Receipt page renders cleanly
        $this->actingAs($staff)
            ->get(route('admin.pos.receipt', $order))
            ->assertOk()
            ->assertSee($order->code)
            ->assertSee('Nguyễn POS');

        // Cash sale works without student card
        $this->actingAs($staff)
            ->postJson(route('admin.pos.checkout'), [
                'payment_method' => PaymentMethod::COUNTER_CASH->value,
                'idempotency_key' => 'staff-pos-cash-001',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', 35000);

        $this->assertSame(7, $product->fresh()->stock_quantity);
    }

    public function test_staff_pos_blocks_spending_limit_and_stock_violations(): void
    {
        ['staff' => $staff, 'card' => $card, 'product' => $product] = $this->seedPosFixture(dailyLimit: 30000, bankBalance: 500000, stock: 2);

        // Exceeds daily limit (35,000 > 30,000)
        $this->actingAs($staff)
            ->postJson(route('admin.pos.checkout'), [
                'payment_method' => PaymentMethod::STUDENT_CARD_POS->value,
                'student_card_id' => $card->id,
                'idempotency_key' => 'staff-pos-limit-fail',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'SPENDING_LIMIT_EXCEEDED');

        // Exceeds stock (3 > 2)
        $this->actingAs($staff)
            ->postJson(route('admin.pos.checkout'), [
                'payment_method' => PaymentMethod::COUNTER_CASH->value,
                'idempotency_key' => 'staff-pos-stock-fail',
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'INSUFFICIENT_STOCK');

        $this->assertSame(2, $product->fresh()->stock_quantity);

        // Rejects duplicate product_id rows and >100-char idempotency keys cleanly (422)
        $this->actingAs($staff)
            ->postJson(route('admin.pos.checkout'), [
                'payment_method' => PaymentMethod::COUNTER_CASH->value,
                'idempotency_key' => 'dup-item-key',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ])
            ->assertUnprocessable();

        $this->actingAs($staff)
            ->postJson(route('admin.pos.checkout'), [
                'payment_method' => PaymentMethod::COUNTER_CASH->value,
                'idempotency_key' => str_repeat('k', 105),
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable();
    }
}
