<?php

namespace Tests\Feature\Canteen;

use App\Enums\BankLinkStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StudentCardStatus;
use App\Enums\UserRole;
use App\Models\BankLink;
use App\Models\Category;
use App\Models\Product;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpendingLimitAndOrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function createCustomerCard(bool $linked = true): array
    {
        $user = User::factory()->create([
            'name' => 'Phụ huynh Demo',
            'role' => UserRole::CUSTOMER,
            'zalo_user_id' => 'zalo_'.uniqid(),
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'student_code' => 'HS-'.strtoupper(substr(md5(uniqid()), 0, 8)),
            'full_name' => 'Titkul Student',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '8A2',
            'is_active' => true,
        ]);

        $card = StudentCard::create([
            'student_id' => $student->id,
            'card_code' => strtoupper(substr(md5(uniqid()), 0, 6)),
            'status' => StudentCardStatus::ACTIVE,
            'issued_at' => now(),
        ]);

        if ($linked) {
            BankLink::create([
                'student_card_id' => $card->id,
                'user_id' => $user->id,
                'bank_code' => 'MB',
                'bank_name' => 'MB Bank',
                'account_number_encrypted' => '01234567895678',
                'account_number_masked' => '****5678',
                'account_holder_name' => 'NGUYEN VAN A',
                'national_id_encrypted' => '079204009012',
                'national_id_masked' => '********9012',
                'phone_number' => '0901236789',
                'phone_masked' => '090***6789',
                'email' => null,
                'auto_debit_agreed' => true,
                'auto_debit_agreed_at' => now(),
                'provider_reference' => 'EPAY-MOCK-MB-TEST001',
                'status' => BankLinkStatus::ACTIVE,
                'linked_at' => now(),
            ]);
        }

        return [$user, $student, $card];
    }

    private function createProduct(string $name = 'C2 chanh 500ml', int $price = 10000): Product
    {
        $category = Category::factory()->create(['name' => 'Đồ uống', 'is_active' => true]);

        return Product::factory()->create([
            'category_id' => $category->id,
            'name' => $name,
            'price' => $price,
            'is_available' => true,
        ]);
    }

    public function test_blocks_setting_spending_limit_when_card_is_not_bank_linked_or_not_owned(): void
    {
        [$userUnlinked, , $unlinkedCard] = $this->createCustomerCard(linked: false);
        [$otherUser, , $otherCard] = $this->createCustomerCard(linked: true);

        Sanctum::actingAs($userUnlinked);

        $this->putJson("/api/v1/canteen/cards/{$unlinkedCard->id}/spending-limit", [
            'daily_limit' => 50000,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CARD_NOT_BANK_LINKED');

        $this->putJson("/api/v1/canteen/cards/{$otherCard->id}/spending-limit", [
            'daily_limit' => 50000,
        ])->assertNotFound();

        $this->getJson("/api/v1/canteen/cards/{$otherCard->id}/limit-logs")->assertNotFound();
        $this->getJson("/api/v1/canteen/cards/{$otherCard->id}/orders")->assertNotFound();
    }

    public function test_updates_spending_limit_validates_bounds_and_records_limit_logs(): void
    {
        [$user, , $card] = $this->createCustomerCard(linked: true);
        Sanctum::actingAs($user);

        // Reject invalid values: negative, non-multiple of 1000, exceeding 5_000_000
        $this->putJson("/api/v1/canteen/cards/{$card->id}/spending-limit", ['daily_limit' => -1000])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['daily_limit']);

        $this->putJson("/api/v1/canteen/cards/{$card->id}/spending-limit", ['daily_limit' => 25500])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['daily_limit']);

        $this->putJson("/api/v1/canteen/cards/{$card->id}/spending-limit", ['daily_limit' => 6000000])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['daily_limit']);

        // Set 50,000 VND
        $this->putJson("/api/v1/canteen/cards/{$card->id}/spending-limit", [
            'daily_limit' => 50000,
            'reason' => 'Hạn mức ăn sáng và trưa',
        ])
            ->assertOk()
            ->assertJsonPath('data.spending_limit.daily_limit', 50000)
            ->assertJsonPath('data.spending_limit.spent_today', 0)
            ->assertJsonPath('data.spending_limit.remaining_limit', 50000);

        // Update to 100,000 VND
        $this->putJson("/api/v1/canteen/cards/{$card->id}/spending-limit", [
            'daily_limit' => 100000,
        ])
            ->assertOk()
            ->assertJsonPath('data.spending_limit.daily_limit', 100000);

        // Allow setting 0 VND to pause spending
        $this->putJson("/api/v1/canteen/cards/{$card->id}/spending-limit", [
            'daily_limit' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('data.spending_limit.daily_limit', 0)
            ->assertJsonPath('data.spending_limit.remaining_limit', 0);

        // Limit logs should have 3 entries in reverse chronological order
        $this->getJson("/api/v1/canteen/cards/{$card->id}/limit-logs")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.previous_limit', 100000)
            ->assertJsonPath('data.0.new_limit', 0)
            ->assertJsonPath('data.1.previous_limit', 50000)
            ->assertJsonPath('data.1.new_limit', 100000)
            ->assertJsonPath('data.2.previous_limit', 0)
            ->assertJsonPath('data.2.new_limit', 50000)
            ->assertJsonPath('data.2.reason', 'Hạn mức ăn sáng và trưa');
    }

    public function test_checkout_with_student_card_deducts_limit_and_blocks_when_exceeded(): void
    {
        [$user, , $card] = $this->createCustomerCard(linked: true);
        $product = $this->createProduct('Cơm sườn nướng', 45000);

        Sanctum::actingAs($user);

        $this->putJson("/api/v1/canteen/cards/{$card->id}/spending-limit", [
            'daily_limit' => 60000,
        ])->assertOk();

        // First order: 45,000 <= 60,000 -> succeeds, remaining becomes 15,000
        $orderResp = $this->withHeaders(['Idempotency-Key' => 'card-order-1'])
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'student_card_id' => $card->id,
                'payment_method' => PaymentMethod::STUDENT_CARD->value,
            ]);

        $orderResp->assertCreated()
            ->assertJsonPath('data.student_card_id', $card->id)
            ->assertJsonPath('data.student_name', 'Titkul Student')
            ->assertJsonPath('data.student_class', '8A2')
            ->assertJsonPath('data.store_name', 'THCS Trần Phú')
            ->assertJsonPath('data.payment_method', 'STUDENT_CARD')
            ->assertJsonPath('data.payment_status', 'PAID')
            ->assertJsonPath('data.subtotal_amount', 45000)
            ->assertJsonPath('data.discount_amount', 0)
            ->assertJsonPath('data.total_amount', 45000)
            ->assertJsonPath('data.remaining_limit_snapshot', 15000);

        // Second order: 45,000 > remaining 15,000 -> 422 SPENDING_LIMIT_EXCEEDED
        $this->withHeaders(['Idempotency-Key' => 'card-order-2'])
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'student_card_id' => $card->id,
                'payment_method' => PaymentMethod::STUDENT_CARD->value,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'SPENDING_LIMIT_EXCEEDED')
            ->assertJsonPath('meta.remaining_limit', 15000)
            ->assertJsonPath('meta.required_amount', 45000);

        // Card summary reflects 45,000 spent / 15,000 remaining
        $this->getJson("/api/v1/canteen/cards/{$card->id}")
            ->assertOk()
            ->assertJsonPath('data.spending_limit.spent_today', 45000)
            ->assertJsonPath('data.spending_limit.remaining_limit', 15000);
    }

    public function test_daily_spending_limit_resets_automatically_on_next_vietnam_day(): void
    {
        [$user, , $card] = $this->createCustomerCard(linked: true);
        $product = $this->createProduct('C2 chanh 500ml', 30000);

        Sanctum::actingAs($user);

        $this->putJson("/api/v1/canteen/cards/{$card->id}/spending-limit", [
            'daily_limit' => 30000,
        ])->assertOk();

        $this->withHeaders(['Idempotency-Key' => 'day1-order'])
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'student_card_id' => $card->id,
                'payment_method' => PaymentMethod::STUDENT_CARD->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.remaining_limit_snapshot', 0);

        // Next day: remaining limit resets to full 30,000 automatically
        $this->travel(1)->days();

        $this->getJson("/api/v1/canteen/cards/{$card->id}")
            ->assertOk()
            ->assertJsonPath('data.spending_limit.spent_today', 0)
            ->assertJsonPath('data.spending_limit.remaining_limit', 30000);

        $this->withHeaders(['Idempotency-Key' => 'day2-order'])
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'student_card_id' => $card->id,
                'payment_method' => PaymentMethod::STUDENT_CARD->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.remaining_limit_snapshot', 0);
    }

    public function test_pos_purchase_creates_pos_order_with_idempotency_and_appears_in_card_orders(): void
    {
        [$user, , $card] = $this->createCustomerCard(linked: true);
        $product = $this->createProduct('C2 chanh 500ml', 10000);

        Sanctum::actingAs($user);

        $this->putJson("/api/v1/canteen/cards/{$card->id}/spending-limit", [
            'daily_limit' => 50000,
        ])->assertOk();

        $posResponse = $this->withHeaders(['Idempotency-Key' => 'pos-swipe-001'])
            ->postJson("/api/v1/canteen/cards/{$card->id}/pos-purchases", [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'store_name' => 'THCS Trần Phú',
            ]);

        $posResponse->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::COMPLETED->value)
            ->assertJsonPath('data.payment_method', PaymentMethod::STUDENT_CARD_POS->value)
            ->assertJsonPath('data.payment_status', PaymentStatus::PAID->value)
            ->assertJsonPath('data.store_name', 'THCS Trần Phú')
            ->assertJsonPath('data.total_amount', 10000)
            ->assertJsonPath('data.remaining_limit_snapshot', 40000);

        $this->assertStringStartsWith('POS-', (string) $posResponse->json('data.code'));

        // Idempotent replay returns 200 OK without deducting a second time
        $this->withHeaders(['Idempotency-Key' => 'pos-swipe-001'])
            ->postJson("/api/v1/canteen/cards/{$card->id}/pos-purchases", [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'store_name' => 'THCS Trần Phú',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $posResponse->json('data.id'));

        $this->getJson("/api/v1/canteen/cards/{$card->id}")
            ->assertOk()
            ->assertJsonPath('data.spending_limit.remaining_limit', 40000);

        // Card orders endpoint lists the POS order
        $this->getJson("/api/v1/canteen/cards/{$card->id}/orders")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $posResponse->json('data.id'));
    }

    public function test_cancelling_paid_student_card_order_refunds_daily_spending_limit(): void
    {
        [$user, , $card] = $this->createCustomerCard(linked: true);
        $staff = User::factory()->create(['role' => UserRole::STAFF]);
        $product = $this->createProduct('Bún bò Huế', 35000);

        Sanctum::actingAs($user);

        $this->putJson("/api/v1/canteen/cards/{$card->id}/spending-limit", [
            'daily_limit' => 50000,
        ])->assertOk();

        $order = $this->withHeaders(['Idempotency-Key' => 'refundable-order'])
            ->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'student_card_id' => $card->id,
                'payment_method' => PaymentMethod::STUDENT_CARD->value,
            ])
            ->assertCreated();

        $orderId = $order->json('data.id');

        // Staff cancels the order -> payment_status becomes REFUNDED and daily limit is restored
        Sanctum::actingAs($staff);

        $this->patchJson("/api/v1/admin/orders/{$orderId}/status", [
            'status' => 'CANCELLED',
            'version' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.payment_status', 'REFUNDED');

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/canteen/cards/{$card->id}")
            ->assertOk()
            ->assertJsonPath('data.spending_limit.spent_today', 0)
            ->assertJsonPath('data.spending_limit.remaining_limit', 50000);
    }
}
