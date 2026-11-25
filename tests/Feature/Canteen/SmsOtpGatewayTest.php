<?php

namespace Tests\Feature\Canteen;

use App\Enums\StudentCardStatus;
use App\Models\BankLink;
use App\Models\OtpVerification;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmsOtpGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function createCard(User $parent): StudentCard
    {
        $student = Student::create([
            'user_id' => $parent->id,
            'student_code' => 'HS-SMS-'.random_int(1000, 9999),
            'full_name' => 'Học sinh SMS Test',
            'class_name' => '6A1',
            'school_name' => 'THCS Trần Phú',
            'is_active' => true,
        ]);

        return StudentCard::create([
            'student_id' => $student->id,
            'card_code' => 'SMS'.random_int(100, 999),
            'status' => StudentCardStatus::ACTIVE,
            'issued_at' => now(),
        ]);
    }

    public function test_log_sms_driver_records_delivery_metadata_and_never_logs_plaintext_phone_or_otp(): void
    {
        config()->set('services.sms.driver', 'log');
        config()->set('services.banking.expose_debug_otp', true);

        $loggedSerialized = '';
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) use (&$loggedSerialized): bool {
                $loggedSerialized = $message.' '.json_encode($context, JSON_THROW_ON_ERROR);

                return str_contains($message, 'SMS OTP dispatched')
                    && $context['provider'] === 'log'
                    && $context['phone_masked'] === '090***4567'
                    && ! str_contains($loggedSerialized, '0901234567');
            });

        $parent = User::factory()->create(['phone' => '0901234567', 'phone_verified_at' => now()]);
        $card = $this->createCard($parent);
        Sanctum::actingAs($parent);

        $res = $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", [
            'bank_code' => 'MB',
            'account_number' => '123456789012',
            'account_holder_name' => 'NGUYEN VAN A',
            'national_id' => '079090001234',
            'phone_number' => '0901234567',
            'agreed_terms' => true,
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.otp.delivery_channel', 'SMS')
            ->assertJsonPath('data.otp.delivery_provider', 'log');

        $debugOtp = (string) $res->json('data.otp.debug_otp');
        $this->assertNotEmpty($debugOtp);
        $this->assertStringNotContainsString($debugOtp, $loggedSerialized);

        $otp = OtpVerification::query()->latest('id')->first();
        $this->assertNotNull($otp);
        $this->assertSame('log', $otp->delivery_provider);
        $this->assertSame('SIMULATED', $otp->delivery_status);
        $this->assertStringStartsWith('LOG-SMS-', (string) $otp->delivery_reference);
    }

    public function test_esms_driver_sends_otp_via_rest_api_and_rolls_back_on_provider_failure(): void
    {
        config()->set('services.sms.driver', 'esms');
        config()->set('services.sms.esms.api_key', 'test-esms-key');
        config()->set('services.sms.esms.secret_key', 'test-esms-secret');
        config()->set('services.sms.esms.sandbox', false);

        Http::fake([
            'https://rest.esms.vn/*' => Http::sequence()
                ->push(['CodeResult' => '100', 'SMSID' => 'ESMS-REF-9988'], 200)
                ->push(['CodeResult' => '104', 'ErrorMessage' => 'Brandname invalid'], 200),
        ]);

        $parent = User::factory()->create();
        $card = $this->createCard($parent);
        Sanctum::actingAs($parent);

        // 1. Success via eSMS
        $okRes = $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", [
            'bank_code' => 'MB',
            'account_number' => '123456789012',
            'account_holder_name' => 'NGUYEN VAN B',
            'national_id' => '079090001235',
            'phone_number' => '0912345678',
            'agreed_terms' => true,
        ]);

        $okRes->assertCreated()
            ->assertJsonPath('data.otp.delivery_provider', 'esms');

        $this->assertDatabaseHas('otp_verifications', [
            'delivery_provider' => 'esms',
            'delivery_status' => 'SENT',
            'delivery_reference' => 'ESMS-REF-9988',
        ]);

        // 2. Failure on a new card rolls back BankLink & OtpVerification cleanly (503 SMS_DELIVERY_FAILED)
        $card2 = $this->createCard($parent);
        $failRes = $this->postJson("/api/v1/canteen/cards/{$card2->id}/bank-links", [
            'bank_code' => 'VCB',
            'account_number' => '987654321012',
            'account_holder_name' => 'NGUYEN VAN B',
            'national_id' => '079090001235',
            'phone_number' => '0912345678',
            'agreed_terms' => true,
        ]);

        $failRes->assertStatus(503)
            ->assertJsonPath('error_code', 'SMS_DELIVERY_FAILED');

        $this->assertSame(0, BankLink::query()->where('student_card_id', $card2->id)->count());
    }

    public function test_twilio_driver_formats_vietnam_phone_to_e164_and_records_sid(): void
    {
        config()->set('services.sms.driver', 'twilio');
        config()->set('services.sms.twilio.account_sid', 'AC1234567890');
        config()->set('services.sms.twilio.auth_token', 'twilio-secret-token');
        config()->set('services.sms.twilio.from', '+15005550006');

        Http::fake([
            'https://api.twilio.com/*' => Http::response([
                'sid' => 'SM999888777',
                'status' => 'queued',
            ], 201),
        ]);

        $parent = User::factory()->create();
        $card = $this->createCard($parent);
        Sanctum::actingAs($parent);

        $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", [
            'bank_code' => 'MB',
            'account_number' => '123456789012',
            'account_holder_name' => 'TRAN THI C',
            'national_id' => '079090001236',
            'phone_number' => '0987654321',
            'agreed_terms' => true,
        ])->assertCreated()
            ->assertJsonPath('data.otp.delivery_provider', 'twilio');

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'Accounts/AC1234567890/Messages.json')
                && $request['To'] === '+84987654321'
                && $request['From'] === '+15005550006'
                && str_contains((string) $request['Body'], 'Smart Canteen');
        });

        $this->assertDatabaseHas('otp_verifications', [
            'delivery_provider' => 'twilio',
            'delivery_status' => 'SENT',
            'delivery_reference' => 'SM999888777',
        ]);
    }

    public function test_esms_retries_once_on_5xx_and_unknown_sms_driver_fails_closed(): void
    {
        config()->set('services.sms.driver', 'esms');
        config()->set('services.sms.esms.api_key', 'test-esms-key');
        config()->set('services.sms.esms.secret_key', 'test-esms-secret');
        config()->set('services.sms.esms.sandbox', false);

        Http::fake([
            'https://rest.esms.vn/*' => Http::sequence()
                ->push(['error' => 'Temporary gateway error'], 503)
                ->push(['CodeResult' => '100', 'SMSID' => 'ESMS-RETRY-OK'], 200),
        ]);

        $parent = User::factory()->create();
        $card = $this->createCard($parent);
        Sanctum::actingAs($parent);

        $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", [
            'bank_code' => 'MB',
            'account_number' => '123456789012',
            'account_holder_name' => 'LE VAN D',
            'national_id' => '079090001237',
            'phone_number' => '0909876543',
            'agreed_terms' => true,
        ])->assertCreated()
            ->assertJsonPath('data.otp.delivery_provider', 'esms');

        $this->assertDatabaseHas('otp_verifications', [
            'delivery_reference' => 'ESMS-RETRY-OK',
        ]);

        // Misconfigured SMS_DRIVER fails closed with 503 instead of simulating
        config()->set('services.sms.driver', 'twillio_typo');
        $card2 = $this->createCard($parent);
        $this->postJson("/api/v1/canteen/cards/{$card2->id}/bank-links", [
            'bank_code' => 'MB',
            'account_number' => '123456789012',
            'account_holder_name' => 'LE VAN D',
            'national_id' => '079090001237',
            'phone_number' => '0909876543',
            'agreed_terms' => true,
        ])->assertStatus(503)
            ->assertJsonPath('error_code', 'SMS_DELIVERY_FAILED');
    }

    public function test_sms_failure_rolls_back_resend_otp_and_initiate_unlink_cleanly(): void
    {
        config()->set('services.sms.driver', 'log');
        config()->set('services.banking.expose_debug_otp', true);
        config()->set('services.banking.otp_resend_cooldown_seconds', 5);

        $parent = User::factory()->create();
        $card = $this->createCard($parent);
        Sanctum::actingAs($parent);

        $init = $this->postJson("/api/v1/canteen/cards/{$card->id}/bank-links", [
            'bank_code' => 'MB',
            'account_number' => '123456789012',
            'account_holder_name' => 'PHAM THI E',
            'national_id' => '079090001238',
            'phone_number' => '0901234567',
            'agreed_terms' => true,
        ])->assertCreated();

        $linkId = (int) $init->json('data.bank_link_id');
        $validOtp = (string) $init->json('data.otp.debug_otp');

        // Force SMS failure on resend-otp -> 503 and resend_count remains 0 (not incremented!)
        $this->travel(10)->seconds();
        config()->set('services.sms.driver', 'esms');
        config()->set('services.sms.esms.api_key', '');
        $this->postJson("/api/v1/canteen/bank-links/{$linkId}/resend-otp")
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'SMS_DELIVERY_FAILED');

        $this->assertSame(1, OtpVerification::where('bank_link_id', $linkId)->count());
        $this->assertSame(0, OtpVerification::where('bank_link_id', $linkId)->first()->resend_count);

        // Complete bank link, then force SMS failure on unlink/initiate -> 503 & no unlink OTP row created
        $this->postJson("/api/v1/canteen/bank-links/{$linkId}/verify-otp", ['otp_code' => $validOtp])->assertOk();

        $this->postJson("/api/v1/canteen/bank-links/{$linkId}/unlink/initiate")
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'SMS_DELIVERY_FAILED');

        $this->assertSame(0, OtpVerification::where('bank_link_id', $linkId)->where('purpose', 'BANK_UNLINK')->count());
    }
}
