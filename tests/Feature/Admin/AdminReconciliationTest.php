<?php

namespace Tests\Feature\Admin;

use App\Enums\BankLinkStatus;
use App\Enums\SandboxBankMode;
use App\Enums\StudentCardStatus;
use App\Enums\UserRole;
use App\Models\BankLink;
use App\Models\PaymentTransaction;
use App\Models\ReconciliationItem;
use App\Models\SandboxBankAccount;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use App\Services\ReconciliationService;
use App\Services\SpendingLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_service_detects_matched_and_all_discrepancy_types(): void
    {
        $user = User::factory()->create();
        $today = now(SpendingLimitService::TIMEZONE)->toDateString();

        // 1. Matched
        PaymentTransaction::create([
            'user_id' => $user->id,
            'provider' => 'sandbox_epay',
            'type' => 'CHARGE',
            'amount' => 25000,
            'status' => 'SUCCEEDED',
            'provider_reference' => 'REF-MATCH-1',
            'gateway_txn_ref' => 'POS-1',
        ]);

        // 2. Missing in bank
        PaymentTransaction::create([
            'user_id' => $user->id,
            'provider' => 'sandbox_epay',
            'type' => 'CHARGE',
            'amount' => 30000,
            'status' => 'SUCCEEDED',
            'provider_reference' => 'REF-LOCAL-ONLY',
            'gateway_txn_ref' => 'POS-2',
        ]);

        // 3. Amount mismatch
        PaymentTransaction::create([
            'user_id' => $user->id,
            'provider' => 'sandbox_epay',
            'type' => 'CHARGE',
            'amount' => 40000,
            'status' => 'SUCCEEDED',
            'provider_reference' => 'REF-AMT-DIFF',
            'gateway_txn_ref' => 'POS-3',
        ]);

        // 4. Status mismatch (local TIMEOUT, bank SUCCEEDED)
        PaymentTransaction::create([
            'user_id' => $user->id,
            'provider' => 'sandbox_epay',
            'type' => 'CHARGE',
            'amount' => 15000,
            'status' => 'TIMEOUT',
            'provider_reference' => 'REF-STATUS-DIFF',
            'gateway_txn_ref' => 'POS-4',
        ]);

        $overrideStatement = [
            ['provider_reference' => 'REF-MATCH-1', 'order_code' => 'POS-1', 'amount' => 25000, 'status' => 'SUCCEEDED'],
            ['provider_reference' => 'REF-AMT-DIFF', 'order_code' => 'POS-3', 'amount' => 45000, 'status' => 'SUCCEEDED'],
            ['provider_reference' => 'REF-STATUS-DIFF', 'order_code' => 'POS-4', 'amount' => 15000, 'status' => 'SUCCEEDED'],
            ['provider_reference' => 'REF-BANK-ONLY', 'order_code' => 'POS-9', 'amount' => 50000, 'status' => 'SUCCEEDED'],
        ];

        $batch = app(ReconciliationService::class)->runForDate($today, 'sandbox_epay', null, $overrideStatement);

        $this->assertSame('HAS_DISCREPANCIES', $batch->status);
        $this->assertSame(1, $batch->matched_count);
        $this->assertSame(4, $batch->discrepancy_count);

        $statuses = $batch->items->pluck('status')->sort()->values()->all();
        $this->assertSame([
            'AMOUNT_MISMATCH',
            'MATCHED',
            'MISSING_IN_BANK',
            'MISSING_LOCAL',
            'STATUS_MISMATCH',
        ], $statuses);
    }

    public function test_admin_reconciliation_rbac_cli_command_and_sandbox_account_controls(): void
    {
        $staff = User::factory()->create(['role' => UserRole::STAFF]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $parent = User::factory()->create(['role' => UserRole::CUSTOMER]);
        $today = now(SpendingLimitService::TIMEZONE)->toDateString();

        $student = Student::create([
            'user_id' => $parent->id,
            'student_code' => 'HS-REC-01',
            'full_name' => 'Học sinh Đối Soát',
            'class_name' => '9A1',
            'school_name' => 'THCS Trần Phú',
            'is_active' => true,
        ]);
        $card = StudentCard::create([
            'student_id' => $student->id,
            'card_code' => 'REC001',
            'status' => StudentCardStatus::ACTIVE,
            'issued_at' => now(),
        ]);
        BankLink::create([
            'student_card_id' => $card->id,
            'user_id' => $parent->id,
            'bank_code' => 'MB',
            'bank_name' => 'MB Bank',
            'account_number_encrypted' => '0987654321111',
            'account_number_masked' => '****1111',
            'account_holder_name' => 'PHU HUYNH',
            'national_id_encrypted' => '079090001234',
            'national_id_masked' => '********1234',
            'phone_number' => '0901234567',
            'phone_masked' => '090***4567',
            'auto_debit_agreed' => true,
            'auto_debit_agreed_at' => now(),
            'provider_reference' => 'EPAY-SBX-MB-REC001',
            'status' => BankLinkStatus::ACTIVE,
            'linked_at' => now(),
        ]);

        // Staff can view reconciliation index, but cannot run or update sandbox account
        $this->actingAs($staff)->get(route('admin.canteen.reconciliation.index'))->assertOk();
        $this->actingAs($staff)->post(route('admin.canteen.reconciliation.run'), ['date' => $today])->assertForbidden();
        $this->actingAs($staff)->put(route('admin.canteen.cards.sandbox', $card), [
            'balance' => 10000,
            'simulated_mode' => SandboxBankMode::INSUFFICIENT_FUNDS->value,
        ])->assertForbidden();

        // Admin runs reconciliation via UI and updates sandbox account
        $this->actingAs($admin)->post(route('admin.canteen.reconciliation.run'), [
            'date' => $today,
            'provider' => 'sandbox_epay',
        ])->assertRedirect()->assertSessionHas('status');

        $this->actingAs($admin)->put(route('admin.canteen.cards.sandbox', $card), [
            'balance' => 15000,
            'simulated_mode' => SandboxBankMode::INSUFFICIENT_FUNDS->value,
        ])->assertRedirect()->assertSessionHas('status');

        $sbx = SandboxBankAccount::where('provider_reference', 'EPAY-SBX-MB-REC001')->firstOrFail();
        $this->assertSame(15000, $sbx->balance);
        $this->assertSame(SandboxBankMode::INSUFFICIENT_FUNDS, $sbx->simulated_mode);

        // Create a discrepancy item and verify Staff cannot resolve it (403)
        $batch = app(ReconciliationService::class)->runForDate($today, 'sandbox_epay', $admin, [
            ['provider_reference' => 'REF-EXTRA-ADMIN', 'order_code' => 'POS-X', 'amount' => 10000, 'status' => 'SUCCEEDED'],
        ]);
        $item = ReconciliationItem::where('batch_id', $batch->id)->firstOrFail();

        $this->actingAs($staff)
            ->patch(route('admin.canteen.reconciliation.resolve', $item), ['note' => 'Staff không được xử lý'])
            ->assertForbidden();

        $originalDiscrepancyNote = $item->note;
        $this->actingAs($admin)
            ->patch(route('admin.canteen.reconciliation.resolve', $item), ['note' => 'Đã xác minh đối soát thủ công'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $resolvedItem = $item->fresh();
        $this->assertNotNull($resolvedItem->resolved_at);
        $this->assertSame($admin->id, $resolvedItem->resolved_by);
        $this->assertSame($originalDiscrepancyNote, $resolvedItem->note);
        $this->assertSame('Đã xác minh đối soát thủ công', $resolvedItem->resolution_note);
        $this->assertSame('COMPLETED', $batch->fresh()->status);

        // Idempotent re-run updates the same batch and preserves previously resolved annotation
        $rerunBatch = app(ReconciliationService::class)->runForDate($today, 'sandbox_epay', $admin, [
            ['provider_reference' => 'REF-EXTRA-ADMIN', 'order_code' => 'POS-X', 'amount' => 10000, 'status' => 'SUCCEEDED'],
        ]);
        $this->assertSame($batch->id, $rerunBatch->id);
        $this->assertSame('COMPLETED', $rerunBatch->status);
        $this->assertNotNull($rerunBatch->items->first()->resolved_at);
        $this->assertSame('Đã xác minh đối soát thủ công', $rerunBatch->items->first()->resolution_note);

        // Secondary provider (vnpay) reconciliation fetches its own statement without mixing sandbox_epay
        PaymentTransaction::create([
            'user_id' => $parent->id,
            'provider' => 'vnpay',
            'type' => 'CHARGE',
            'amount' => 35000,
            'status' => 'SUCCEEDED',
            'provider_reference' => 'VNP-TX-001',
            'gateway_txn_ref' => 'VNP-ORD-001',
        ]);
        $vnpayBatch = app(ReconciliationService::class)->runForDate($today, 'vnpay', $admin);
        $this->assertSame('vnpay', $vnpayBatch->provider);
        $this->assertSame(1, $vnpayBatch->matched_count);
        $this->assertSame(0, $vnpayBatch->discrepancy_count);
        $this->assertSame('COMPLETED', $vnpayBatch->status);

        // Sandbox account controls return 403 when banking driver is not sandbox
        config()->set('services.banking.driver', 'mock');
        $this->actingAs($admin)->put(route('admin.canteen.cards.sandbox', $card), [
            'balance' => 99000,
            'simulated_mode' => SandboxBankMode::NORMAL->value,
        ])->assertForbidden();
        config()->set('services.banking.driver', 'sandbox');

        // Artisan command works cleanly & rejects invalid date/provider cleanly
        $this->artisan('canteen:reconcile', ['date' => $today, '--provider' => 'sandbox_epay'])
            ->assertSuccessful();
        $this->artisan('canteen:reconcile', ['date' => 'not-a-date'])
            ->assertFailed();
    }
}
