<?php

namespace Tests\Feature\Canteen;

use App\Enums\BankLinkStatus;
use App\Enums\StudentCardStatus;
use App\Enums\UserRole;
use App\Models\BankLink;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use Database\Seeders\StudentCardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DigitalCanteenBankLinkTest extends TestCase
{
    use RefreshDatabase;

    private function createCustomerWithCard(
        string $cardCode = 'F156BA',
        StudentCardStatus $status = StudentCardStatus::ACTIVE,
    ): array {
        $user = User::factory()->create([
            'name' => 'Phụ huynh Demo',
            'role' => UserRole::CUSTOMER,
            'zalo_user_id' => 'zalo_'.uniqid(),
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'student_code' => 'HS-'.strtoupper(substr(md5($cardCode.uniqid()), 0, 8)),
            'full_name' => 'Titkul Student',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '8A2',
            'is_active' => true,
        ]);

        $card = StudentCard::create([
            'student_id' => $student->id,
            'card_code' => $cardCode,
            'status' => $status,
            'issued_at' => now(),
        ]);

        return [$user, $student, $card];
    }

    private function validBankPayload(array $overrides = []): array
    {
        return array_merge([
            'bank_code' => 'MB',
            'account_number' => '01234567895678',
            'account_holder_name' => 'Nguyen Van A',
            'national_id' => '079204009012',
            'phone_number' => '0901236789',
            'email' => 'phuhuynh@example.com',
            'agreed_terms' => true,
        ], $overrides);
    }

    public function test_lists_supported_banks_publicly(): void
    {
        $response = $this->getJson('/api/v1/canteen/banks');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['code', 'name', 'short_name', 'account_min_length', 'account_max_length'],
                ],
            ]);

        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertContains('MB', $codes);
        $this->assertContains('VCB', $codes);
    }

    public function test_requires_authentication_for_canteen_cards(): void
    {
        $this->getJson('/api/v1/canteen/cards')->assertUnauthorized();
    }

    public function test_lists_only_own_cards_and_blocks_cross_user_access_with_404(): void
    {
        [$userA, , $cardA] = $this->createCustomerWithCard('CARDAA');
        [$userB, , $cardB] = $this->createCustomerWithCard('CARDBB');

        Sanctum::actingAs($userA);

        $this->getJson('/api/v1/canteen/cards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.card_code', 'CARDAA')
            ->assertJsonPath('data.0.student.school_name', 'THCS Trần Phú')
            ->assertJsonPath('data.0.student.full_name', 'Titkul Student')
            ->assertJsonPath('data.0.student.class_name', '8A2')
            ->assertJsonPath('data.0.status', 'ACTIVE')
            ->assertJsonPath('data.0.is_bank_linked', false)
            ->assertJsonPath('data.0.bank_link', null);

        $this->getJson("/api/v1/canteen/cards/{$cardA->id}")->assertOk();
        $this->getJson("/api/v1/canteen/cards/{$cardB->id}")->assertNotFound();
    }

    public function test_validates_bank_link_request_strictly(): void
    {
        [$user, , $card] = $this->createCustomerWithCard('VAL001');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", [
            'bank_code' => 'UNKNOWN_BANK',
            'account_number' => 'ABC12',
            'account_holder_name' => 'A',
            'national_id' => '12345',
            'phone_number' => '0123456789',
            'email' => 'not-an-email',
            'agreed_terms' => false,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'bank_code',
                'account_number',
                'account_holder_name',
                'national_id',
                'phone_number',
                'email',
                'agreed_terms',
            ]);
    }

    public function test_blocks_bank_link_when_card_is_locked(): void
    {
        [$user, , $card] = $this->createCustomerWithCard('LOCK01', StudentCardStatus::LOCKED);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", $this->validBankPayload())
            ->assertStatus(422);
    }

    public function test_completes_full_bank_linking_flow_with_encryption_masking_and_hashed_otp(): void
    {
        config()->set('services.banking.expose_debug_otp', true);

        [$user, , $card] = $this->createCustomerWithCard('F156BA');
        Sanctum::actingAs($user);

        $initResponse = $this->postJson(
            "/api/v1/canteen/cards/{$card->id}/bank-links",
            $this->validBankPayload(['email' => null]),
        );

        $initResponse->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_OTP')
            ->assertJsonPath('data.bank_code', 'MB')
            ->assertJsonPath('data.account_number_masked', '****5678')
            ->assertJsonPath('data.phone_masked', '090***6789')
            ->assertJsonPath('data.otp.expires_in_seconds', 180)
            ->assertJsonPath('data.otp.max_attempts', 5);

        $bankLinkId = $initResponse->json('data.bank_link_id');
        $debugOtp = $initResponse->json('data.otp.debug_otp');
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $debugOtp);

        // Verify sensitive data is encrypted / hashed at rest in raw DB rows
        $rawLink = DB::table('bank_links')->where('id', $bankLinkId)->first();
        $this->assertNotNull($rawLink);
        $this->assertNotSame('01234567895678', $rawLink->account_number_encrypted);
        $this->assertStringNotContainsString('01234567895678', (string) $rawLink->account_number_encrypted);
        $this->assertNotSame('079204009012', $rawLink->national_id_encrypted);
        $this->assertStringNotContainsString('079204009012', (string) $rawLink->national_id_encrypted);

        $rawOtp = DB::table('otp_verifications')->where('bank_link_id', $bankLinkId)->first();
        $this->assertNotNull($rawOtp);
        $this->assertNotSame($debugOtp, $rawOtp->code_hash);

        // Verify OTP completes linking
        $verifyResponse = $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/verify-otp", [
            'otp_code' => $debugOtp,
        ]);

        $verifyResponse->assertOk()
            ->assertJsonPath('data.card_code', 'F156BA')
            ->assertJsonPath('data.is_bank_linked', true)
            ->assertJsonPath('data.bank_link.bank_code', 'MB')
            ->assertJsonPath('data.bank_link.account_number_masked', '****5678')
            ->assertJsonPath('data.bank_link.national_id_masked', '********9012')
            ->assertJsonPath('data.bank_link.phone_masked', '090***6789')
            ->assertJsonPath('data.bank_link.account_holder_name', 'NGUYEN VAN A')
            ->assertJsonPath('data.bank_link.status', 'ACTIVE');

        // Ensure plaintext account/CCCD/OTP are NEVER leaked in JSON
        $jsonBody = json_encode($verifyResponse->json());
        $this->assertStringNotContainsString('01234567895678', (string) $jsonBody);
        $this->assertStringNotContainsString('079204009012', (string) $jsonBody);

        // Attempting a second link on an already ACTIVE card returns 409 Conflict
        $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", $this->validBankPayload())
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'CARD_ALREADY_LINKED');
    }

    public function test_handles_invalid_otp_attempts_and_locks_after_five_failures(): void
    {
        config()->set('services.banking.expose_debug_otp', true);

        [$user, , $card] = $this->createCustomerWithCard('OTP001');
        Sanctum::actingAs($user);

        $init = $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", $this->validBankPayload())->assertCreated();
        $bankLinkId = $init->json('data.bank_link_id');
        $correctOtp = (string) $init->json('data.otp.debug_otp');
        $wrongOtp = $correctOtp === '000000' ? '999999' : '000000';

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/verify-otp", [
                'otp_code' => $wrongOtp,
            ])
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'OTP_INVALID')
                ->assertJsonPath('meta.remaining_attempts', 5 - $attempt);
        }

        // 5th invalid attempt locks the OTP session
        $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/verify-otp", [
            'otp_code' => $wrongOtp,
        ])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'OTP_MAX_ATTEMPTS_EXCEEDED');

        // Even correct OTP is now rejected
        $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/verify-otp", [
            'otp_code' => $correctOtp,
        ])->assertStatus(429);

        // Lockout must also mark bank_link FAILED and block resend-otp bypass (even after cooldown)
        $this->assertSame(
            BankLinkStatus::FAILED,
            BankLink::query()->findOrFail($bankLinkId)->status,
        );

        $this->travel(35)->seconds();
        $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/resend-otp")
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'OTP_MAX_ATTEMPTS_EXCEEDED');
    }

    public function test_rejects_expired_otp_after_ttl(): void
    {
        config()->set('services.banking.expose_debug_otp', true);

        [$user, , $card] = $this->createCustomerWithCard('OTP002');
        Sanctum::actingAs($user);

        $init = $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", $this->validBankPayload())->assertCreated();
        $bankLinkId = $init->json('data.bank_link_id');
        $correctOtp = (string) $init->json('data.otp.debug_otp');

        $this->travel(181)->seconds();

        $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/verify-otp", [
            'otp_code' => $correctOtp,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OTP_EXPIRED');
    }

    public function test_enforces_resend_cooldown_max_resends_and_invalidates_old_otp(): void
    {
        config()->set('services.banking.expose_debug_otp', true);

        [$user, , $card] = $this->createCustomerWithCard('OTP003');
        Sanctum::actingAs($user);

        $init = $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", $this->validBankPayload())->assertCreated();
        $bankLinkId = $init->json('data.bank_link_id');
        $firstOtp = (string) $init->json('data.otp.debug_otp');

        // Immediate resend blocked by 30s cooldown
        $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/resend-otp")
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'OTP_RESEND_COOLDOWN');

        $this->travel(31)->seconds();

        $resend1 = $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/resend-otp")
            ->assertOk()
            ->assertJsonPath('data.otp.remaining_resends', 2);

        $secondOtp = (string) $resend1->json('data.otp.debug_otp');

        // Resend 2 and 3 succeed after cooldown
        $this->travel(31)->seconds();
        $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/resend-otp")
            ->assertOk()
            ->assertJsonPath('data.otp.remaining_resends', 1);

        $this->travel(31)->seconds();
        $resend3 = $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/resend-otp")
            ->assertOk()
            ->assertJsonPath('data.otp.remaining_resends', 0);

        $latestOtp = (string) $resend3->json('data.otp.debug_otp');

        // 4th resend blocked by max_resends
        $this->travel(31)->seconds();
        $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/resend-otp")
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'OTP_MAX_RESENDS_EXCEEDED');

        // Previous OTPs are invalidated; latest OTP succeeds
        if ($firstOtp !== $latestOtp) {
            $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/verify-otp", [
                'otp_code' => $firstOtp,
            ])->assertStatus(422);
        }

        if ($secondOtp !== $latestOtp && $secondOtp !== $firstOtp) {
            $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/verify-otp", [
                'otp_code' => $secondOtp,
            ])->assertStatus(422);
        }

        $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/verify-otp", [
            'otp_code' => $latestOtp,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_bank_linked', true);
    }

    public function test_reinitiating_bank_link_to_fix_phone_invalidates_previous_pending_session(): void
    {
        config()->set('services.banking.expose_debug_otp', true);

        [$user, , $card] = $this->createCustomerWithCard('OTP006');
        Sanctum::actingAs($user);

        $first = $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", $this->validBankPayload([
            'phone_number' => '0901112222',
        ]))->assertCreated();

        $firstBankLinkId = $first->json('data.bank_link_id');
        $firstOtp = (string) $first->json('data.otp.debug_otp');

        $second = $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", $this->validBankPayload([
            'phone_number' => '0983334444',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.phone_masked', '098***4444');

        $secondBankLinkId = $second->json('data.bank_link_id');
        $secondOtp = (string) $second->json('data.otp.debug_otp');

        $this->assertSame(
            BankLinkStatus::EXPIRED,
            BankLink::query()->findOrFail($firstBankLinkId)->status,
        );

        // Old pending link can no longer be verified
        $this->postJson("/api/v1/canteen/bank-links/{$firstBankLinkId}/verify-otp", [
            'otp_code' => $firstOtp,
        ])->assertStatus(409);

        // New link verifies cleanly
        $this->postJson("/api/v1/canteen/bank-links/{$secondBankLinkId}/verify-otp", [
            'otp_code' => $secondOtp,
        ])
            ->assertOk()
            ->assertJsonPath('data.bank_link.phone_masked', '098***4444');
    }

    public function test_blocks_cross_user_otp_verify_and_resend_with_404(): void
    {
        config()->set('services.banking.expose_debug_otp', true);

        [$userA, , $cardA] = $this->createCustomerWithCard('OWN001');
        [$userB] = $this->createCustomerWithCard('OWN002');

        Sanctum::actingAs($userA);
        $init = $this->postJson("/api/v1/canteen/cards/{$cardA->id}/bank-links", $this->validBankPayload())->assertCreated();
        $bankLinkId = $init->json('data.bank_link_id');
        $otp = (string) $init->json('data.otp.debug_otp');

        Sanctum::actingAs($userB);
        $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/verify-otp", ['otp_code' => $otp])->assertNotFound();
        $this->postJson("/api/v1/canteen/bank-links/{$bankLinkId}/resend-otp")->assertNotFound();
    }

    public function test_hides_debug_otp_when_disabled(): void
    {
        config()->set('services.banking.expose_debug_otp', false);

        [$user, , $card] = $this->createCustomerWithCard('SEC001');
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", $this->validBankPayload())
            ->assertCreated();

        $this->assertArrayNotHasKey('debug_otp', $response->json('data.otp'));
    }

    public function test_student_card_seeder_is_idempotent_and_seeds_default_card(): void
    {
        $this->seed(StudentCardSeeder::class);
        $this->seed(StudentCardSeeder::class);

        $this->assertDatabaseCount('student_cards', 1);
        $this->assertDatabaseHas('student_cards', [
            'card_code' => 'F156BA',
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('students', [
            'full_name' => 'Titkul Student',
            'school_name' => 'THCS Trần Phú',
            'class_name' => '8A2',
        ]);
    }
}
