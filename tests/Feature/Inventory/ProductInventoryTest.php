<?php

namespace Tests\Feature\Inventory;

use App\Enums\BankLinkStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
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
use App\Services\OrderService;
use App\Services\SpendingLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_decrements_stock_blocks_oversell_and_restores_stock_on_cancel(): void
    {
        $category = Category::create(['name' => 'Đồ uống kho', 'is_active' => true]);
        $tracked = Product::create([
            'category_id' => $category->id,
            'name' => 'Sữa tươi đóng hộp',
            'price' => 15000,
            'is_available' => true,
            'stock_quantity' => 3,
            'low_stock_threshold' => 2,
        ]);
        $unlimited = Product::create([
            'category_id' => $category->id,
            'name' => 'Cơm nóng tự chọn',
            'price' => 30000,
            'is_available' => true,
            'stock_quantity' => null,
            'low_stock_threshold' => 5,
        ]);

        // Public API exposes inventory fields
        $this->getJson('/api/v1/products/'.$tracked->id)
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 3)
            ->assertJsonPath('data.low_stock_threshold', 2)
            ->assertJsonPath('data.is_in_stock', true);

        $parent = User::factory()->create(['role' => UserRole::PARENT]);
        $staff = User::factory()->create(['role' => UserRole::STAFF]);
        Sanctum::actingAs($parent);

        // Requesting more than stock_quantity fails with 422 INSUFFICIENT_STOCK
        $this->withHeader('Idempotency-Key', 'inv-oversell-1')
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $tracked->id, 'quantity' => 4]],
                'payment_method' => PaymentMethod::COUNTER_CASH->value,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('meta.available_stock', 3)
            ->assertJsonPath('meta.requested_quantity', 4);

        $this->assertSame(3, $tracked->fresh()->stock_quantity);

        // Valid order decrements tracked stock atomically, keeps unlimited null
        $res = $this->withHeader('Idempotency-Key', 'inv-ok-1')
            ->postJson('/api/v1/orders', [
                'items' => [
                    ['product_id' => $tracked->id, 'quantity' => 2],
                    ['product_id' => $unlimited->id, 'quantity' => 1],
                ],
                'payment_method' => PaymentMethod::COUNTER_CASH->value,
            ])
            ->assertCreated();

        $this->assertSame(1, $tracked->fresh()->stock_quantity);
        $this->assertNull($unlimited->fresh()->stock_quantity);

        // Cancelling the order restores tracked stock atomically
        $order = Order::findOrFail((int) $res->json('data.id'));
        app(OrderService::class)->transitionStatus($order, OrderStatus::CANCELLED, 1, $staff);

        $this->assertSame(3, $tracked->fresh()->stock_quantity);
        $this->assertNull($unlimited->fresh()->stock_quantity);
    }

    public function test_failed_bank_payment_rolls_back_inventory_decrement(): void
    {
        $category = Category::create(['name' => 'Ăn nhẹ', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Bánh mì thịt',
            'price' => 25000,
            'is_available' => true,
            'stock_quantity' => 5,
            'low_stock_threshold' => 2,
        ]);

        $parent = User::factory()->create(['role' => UserRole::PARENT]);
        $student = Student::create([
            'user_id' => $parent->id,
            'student_code' => 'HS-INV-01',
            'full_name' => 'Học sinh Kho',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '7A1',
            'is_active' => true,
        ]);
        $card = StudentCard::create([
            'student_id' => $student->id,
            'card_code' => 'INV001',
            'status' => StudentCardStatus::ACTIVE,
            'issued_at' => now(),
        ]);
        $bankRef = 'EPAY-SBX-INV-0000';
        BankLink::create([
            'student_card_id' => $card->id,
            'user_id' => $parent->id,
            'bank_code' => 'MB',
            'bank_name' => 'MB Bank',
            'account_number_encrypted' => '0987654320000',
            'account_number_masked' => '****0000',
            'account_holder_name' => 'PHU HUYNH KHO',
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
            'account_number_masked' => '****0000',
            'balance' => 0,
            'simulated_mode' => SandboxBankMode::INSUFFICIENT_FUNDS,
        ]);
        SpendingLimit::create([
            'student_card_id' => $card->id,
            'daily_limit' => 100000,
            'spent_today' => 0,
            'spent_date' => now(SpendingLimitService::TIMEZONE)->toDateString(),
        ]);

        Sanctum::actingAs($parent);
        $this->withHeader('Idempotency-Key', 'inv-bank-fail-1')
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'payment_method' => PaymentMethod::STUDENT_CARD->value,
                'student_card_id' => $card->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'INSUFFICIENT_BANK_BALANCE');

        // Stock must remain completely untouched after rollback
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_admin_can_update_stock_and_staff_is_forbidden(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $staff = User::factory()->create(['role' => UserRole::STAFF]);
        $category = Category::create(['name' => 'Món chính', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Phở bò',
            'price' => 45000,
            'is_available' => true,
            'stock_quantity' => 2,
            'low_stock_threshold' => 5,
        ]);

        $this->actingAs($staff)
            ->patch(route('admin.products.stock', $product), ['mode' => 'add', 'quantity' => 10])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('admin.products.stock', $product), ['mode' => 'add', 'quantity' => 10])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(12, $product->fresh()->stock_quantity);

        $this->actingAs($admin)
            ->patch(route('admin.products.stock', $product), ['mode' => 'unlimited'])
            ->assertRedirect();

        $this->assertNull($product->fresh()->stock_quantity);

        // Metadata-only edit preserves concurrent stock decrements when original_stock_quantity matches stock_quantity
        $product->update(['stock_quantity' => 8]);
        // Simulate concurrent order decrementing DB stock from 10 -> 8 while Admin had 10 open in edit form
        $this->actingAs($admin)
            ->put(route('admin.products.update', $product), [
                'category_id' => $category->id,
                'name' => 'Phở bò đặc biệt',
                'price' => 50000,
                'is_available' => '1',
                'stock_quantity' => '10',
                'original_stock_quantity' => '10',
                'low_stock_threshold' => '5',
            ])
            ->assertRedirect('/admin/products');

        $this->assertSame('Phở bò đặc biệt', $product->fresh()->name);
        $this->assertSame(8, $product->fresh()->stock_quantity);
    }
}
