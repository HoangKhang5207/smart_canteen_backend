<?php

namespace Tests\Feature\Canteen;

use App\Enums\BankLinkStatus;
use App\Enums\OtpPurpose;
use App\Enums\StudentCardStatus;
use App\Models\BankLink;
use App\Models\OtpVerification;
use App\Models\SpendingLimit;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use App\Services\SpendingLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BankUnlinkOtpTest extends TestCase
{
    use RefreshDatabase;

    private function createLinkedCard(User $parent, StudentCardStatus $cardStatus = StudentCardStatus::ACTIVE): array
    {
        $student = Student::create([
            'user_id' => $parent->id,
            'student_code' => 'HS-'.str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT),
            'full_name' => 'Nguyễn Minh Khang',
            'class_name' => '8A2',
            'school_name' => 'THCS Trần Phú',
            'is_active' => true,
        ]);

        $card = StudentCard::create([
            'student_id' => $student->id,
            'card_code' => strtoupper(substr(md5((string) random_int(1, 999999)), 0, 6)),
            'status' => $cardStatus,
            'issued_at' => now()->subDays(10),
        ]);

        $bankLink = BankLink::create([
            'student_card_id' => $card->id,
            'user_id' => $parent->id,
            'bank_code' => 'MB',
            'bank_name' => 'MB Bank',
            'account_number_encrypted' => '0987654321012',
            'account_number_masked' => '*********1012',
            'account_holder_name' => 'NGUYEN VAN PHU HUYNH',
            'national_id_encrypted' => '079090001234',
            'national_id_masked' => '********1234',
            'phone_number' => '0901234567',
            'phone_masked' => '090****567',
            'email' => 'phuhuynh@example.com',
            'auto_debit_agreed' => true,
            'auto_debit_agreed_at' => now()->subDays(5),
            'provider_reference' => 'EPAY-LINK-TEST-001',
            'status' => BankLinkStatus::ACTIVE,
            'linked_at' => now()->subDays(5),
        ]);

        return [$card, $bankLink];
    }

    public function test_parent_can_initiate_and_verify_bank_unlink_otp_and_preserve_spent_today(): void
    {
        config()->set('services.banking.expose_debug_otp', true);

        $parent = User::factory()->create();
        [$card, $bankLink] = $this->createLinkedCard($parent);

        $today = app(SpendingLimitService::class)->todayDate();
        SpendingLimit::create([
            'student_card_id' => $card->id,
            'daily_limit' => 100000,
            'spent_today' => 35000,
            'spent_date' => $today,
        ]);

        Sanctum::actingAs($parent);

        $initRes = $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/initiate");

        $initRes->assertCreated()
            ->assertJsonPath('data.bank_link_id', $bankLink->id)
            ->assertJsonPath('data.student_card_id', $card->id)
            ->assertJsonPath('data.purpose', OtpPurpose::BANK_UNLINK->value)
            ->assertJsonPath('data.status', BankLinkStatus::ACTIVE->value)
            ->assertJsonPath('data.phone_masked', '090****567')
            ->assertJsonStructure(['data' => ['otp' => ['debug_otp', 'expires_in_seconds', 'resend_cooldown_seconds']]]);

        $otpCode = (string) $initRes->json('data.otp.debug_otp');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otpCode);

        $verifyRes = $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/verify-otp", [
            'otp_code' => $otpCode,
        ]);

        $verifyRes->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonPath('data.is_bank_linked', false)
            ->assertJsonPath('data.bank_link', null)
            ->assertJsonPath('data.spending_limit.daily_limit', 100000)
            ->assertJsonPath('data.spending_limit.spent_today', 35000)
            ->assertJsonPath('data.spending_limit.remaining_limit', 65000);

        $bankLink->refresh();
        $this->assertSame(BankLinkStatus::UNLINKED, $bankLink->status);
        $this->assertNotNull($bankLink->unlinked_at);

        // Subsequent unlink initiation on already unlinked record returns 409
        $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/initiate")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'BANK_LINK_NOT_ACTIVE');

        // Updating spending limit is blocked after unlinking (422 CARD_NOT_BANK_LINKED)
        $this->putJson("/api/v1/canteen/cards/{$card->id}/spending-limit", [
            'daily_limit' => 50000,
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'CARD_NOT_BANK_LINKED');
    }

    public function test_unlink_otp_lockout_invalidates_otp_without_breaking_active_bank_link(): void
    {
        config()->set('services.banking.expose_debug_otp', true);
        config()->set('services.banking.otp_max_attempts', 3);

        $parent = User::factory()->create();
        [$card, $bankLink] = $this->createLinkedCard($parent);
        Sanctum::actingAs($parent);

        $initRes = $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/initiate")->assertCreated();
        $validOtp = (string) $initRes->json('data.otp.debug_otp');
        $wrongOtp = $validOtp === '000000' ? '111111' : '000000';

        $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/verify-otp", ['otp_code' => $wrongOtp])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OTP_INVALID')
            ->assertJsonPath('meta.remaining_attempts', 2);

        $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/verify-otp", ['otp_code' => $wrongOtp])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OTP_INVALID')
            ->assertJsonPath('meta.remaining_attempts', 1);

        $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/verify-otp", ['otp_code' => $wrongOtp])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'OTP_MAX_ATTEMPTS_EXCEEDED');

        // Resend is blocked on locked OTP session
        $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/resend-otp")
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'OTP_MAX_ATTEMPTS_EXCEEDED');

        // Active bank link must remain ACTIVE!
        $bankLink->refresh();
        $this->assertSame(BankLinkStatus::ACTIVE, $bankLink->status);
    }

    public function test_unlink_otp_resend_enforces_cooldown_and_invalidates_previous_code(): void
    {
        config()->set('services.banking.expose_debug_otp', true);
        config()->set('services.banking.otp_resend_cooldown_seconds', 30);

        $parent = User::factory()->create();
        [$card, $bankLink] = $this->createLinkedCard($parent);
        Sanctum::actingAs($parent);

        Carbon::setTestNow(Carbon::parse('2026-11-25 09:00:00', 'Asia/Ho_Chi_Minh'));
        try {
            $firstInit = $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/initiate")->assertCreated();
            $firstOtp = (string) $firstInit->json('data.otp.debug_otp');

            // Immediate resend blocked by cooldown
            $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/resend-otp")
                ->assertStatus(429)
                ->assertJsonPath('error_code', 'OTP_RESEND_COOLDOWN');

            Carbon::setTestNow(Carbon::parse('2026-11-25 09:00:35', 'Asia/Ho_Chi_Minh'));
            $resendRes = $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/resend-otp")
                ->assertOk()
                ->assertJsonPath('data.purpose', OtpPurpose::BANK_UNLINK->value)
                ->assertJsonPath('data.otp.remaining_resends', 2);

            $secondOtp = (string) $resendRes->json('data.otp.debug_otp');

            // First OTP row is invalidated and cannot be used (even if code differs)
            $firstRow = OtpVerification::where('bank_link_id', $bankLink->id)
                ->where('purpose', OtpPurpose::BANK_UNLINK->value)
                ->orderBy('id')
                ->first();
            $this->assertNotNull($firstRow?->invalidated_at);

            if ($firstOtp !== $secondOtp) {
                $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/verify-otp", [
                    'otp_code' => $firstOtp,
                ])->assertStatus(422)
                    ->assertJsonPath('error_code', 'OTP_INVALID');
            }

            // Latest OTP row is verified with secondOtp
            $this->assertSame(2, OtpVerification::where('bank_link_id', $bankLink->id)->where('purpose', OtpPurpose::BANK_UNLINK->value)->count());

            $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/verify-otp", [
                'otp_code' => $secondOtp,
            ])->assertOk()->assertJsonPath('data.is_bank_linked', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_unlink_otp_enforces_expiration_and_max_resends_exceeded(): void
    {
        config()->set('services.banking.expose_debug_otp', true);
        config()->set('services.banking.otp_ttl_seconds', 60);
        config()->set('services.banking.otp_resend_cooldown_seconds', 10);
        config()->set('services.banking.otp_max_resends', 1);

        $parent = User::factory()->create();
        [, $bankLink] = $this->createLinkedCard($parent);
        Sanctum::actingAs($parent);

        Carbon::setTestNow(Carbon::parse('2026-11-25 10:00:00', 'Asia/Ho_Chi_Minh'));
        try {
            $init = $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/initiate")->assertCreated();
            $firstOtp = (string) $init->json('data.otp.debug_otp');

            // Advance past 60s TTL -> verify returns 422 OTP_EXPIRED
            Carbon::setTestNow(Carbon::parse('2026-11-25 10:01:05', 'Asia/Ho_Chi_Minh'));
            $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/verify-otp", [
                'otp_code' => $firstOtp,
            ])->assertStatus(422)
                ->assertJsonPath('error_code', 'OTP_EXPIRED');

            // First resend succeeds (uses the 1 allowed resend)
            $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/resend-otp")
                ->assertOk()
                ->assertJsonPath('data.otp.remaining_resends', 0);

            // Second resend after cooldown returns 429 OTP_MAX_RESENDS_EXCEEDED
            Carbon::setTestNow(Carbon::parse('2026-11-25 10:01:20', 'Asia/Ho_Chi_Minh'));
            $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/resend-otp")
                ->assertStatus(429)
                ->assertJsonPath('error_code', 'OTP_MAX_RESENDS_EXCEEDED');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_parent_can_unlink_locked_card_and_idor_is_blocked_across_all_unlink_endpoints(): void
    {
        config()->set('services.banking.expose_debug_otp', true);

        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        [$lockedCard, $bankLink] = $this->createLinkedCard($owner, StudentCardStatus::LOCKED);

        // Owner initiates unlink so an active OTP exists
        Sanctum::actingAs($owner);
        $initRes = $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/initiate")->assertCreated();
        $otp = (string) $initRes->json('data.otp.debug_otp');

        // Stranger cannot access initiate, verify-otp, or resend-otp on owner's bank link (404)
        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/initiate")->assertNotFound();
        $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/verify-otp", ['otp_code' => $otp])->assertNotFound();
        $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/resend-otp")->assertNotFound();

        // Owner CAN unlink even when card is LOCKED
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/canteen/bank-links/{$bankLink->id}/unlink/verify-otp", [
            'otp_code' => $otp,
        ])->assertOk()
            ->assertJsonPath('data.id', $lockedCard->id)
            ->assertJsonPath('data.status', StudentCardStatus::LOCKED->value)
            ->assertJsonPath('data.is_bank_linked', false);
    }

    public function test_pos_purchase_rejects_non_string_store_name_with_422_validation_error(): void
    {
        $parent = User::factory()->create();
        [$card] = $this->createLinkedCard($parent);
        Sanctum::actingAs($parent);

        $this->withHeader('Idempotency-Key', 'pos-invalid-store-name-type')
            ->postJson("/api/v1/canteen/cards/{$card->id}/pos-purchases", [
                'items' => [['product_id' => 1, 'quantity' => 1]],
                'store_name' => ['not-a-string'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['store_name']);
    }
}
