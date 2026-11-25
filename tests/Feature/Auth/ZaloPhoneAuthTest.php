<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZaloPhoneAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_zalo_phone_exchanges_token_normalizes_number_and_creates_parent(): void
    {
        config()->set('services.zalo.app_secret', 'test-zalo-secret-key');

        Http::fake([
            'https://graph.zalo.me/v2.0/me/info*' => Http::response([
                'data' => ['number' => '84901234567'],
                'error' => 0,
                'message' => 'Success',
            ], 200),
            'https://graph.zalo.me/v2.0/me*' => Http::response([
                'id' => 'zalo_parent_9001',
                'name' => 'Nguyễn Thị Phụ Huynh',
                'error' => 0,
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/auth/zalo-phone', [
            'access_token' => 'zalo-access-token-1',
            'phone_token' => 'zalo-phone-token-1',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.phone', '0901234567')
            ->assertJsonPath('data.user.phone_masked', '090***4567')
            ->assertJsonPath('data.user.has_verified_phone', true)
            ->assertJsonPath('data.user.zalo_user_id', 'zalo_parent_9001')
            ->assertJsonPath('data.user.name', 'Nguyễn Thị Phụ Huynh')
            ->assertJsonPath('data.user.role', UserRole::CUSTOMER->value);

        $token = (string) $response->json('data.token');
        $this->assertNotEmpty($token);

        // Token works for /auth/me and can be revoked via /auth/logout
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.phone', '0901234567');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_zalo_phone_preserves_existing_admin_role_and_blocks_account_conflict(): void
    {
        config()->set('services.zalo.app_secret', 'test-zalo-secret-key');

        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'phone' => '0912345678',
            'zalo_user_id' => null,
        ]);

        Http::fake([
            'https://graph.zalo.me/v2.0/me/info*' => Http::response([
                'data' => ['number' => '84912345678'],
                'error' => 0,
            ], 200),
            'https://graph.zalo.me/v2.0/me*' => Http::response([
                'id' => 'zalo_admin_link_1',
                'name' => 'Admin Zalo',
                'error' => 0,
            ], 200),
        ]);

        $this->postJson('/api/v1/auth/zalo-phone', [
            'access_token' => 'zalo-access-token-admin',
            'phone_token' => 'zalo-phone-token-admin',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.user.role', UserRole::ADMIN->value)
            ->assertJsonPath('data.user.zalo_user_id', 'zalo_admin_link_1');
    }

    public function test_login_zalo_phone_blocks_account_conflict_when_phone_and_zalo_belong_to_different_users(): void
    {
        config()->set('services.zalo.app_secret', 'test-zalo-secret-key');

        User::factory()->create([
            'phone' => '0912345678',
            'zalo_user_id' => 'zalo_admin_link_1',
        ]);

        User::factory()->create([
            'phone' => '0988777666',
            'zalo_user_id' => 'zalo_other_person',
        ]);

        Http::fake([
            'https://graph.zalo.me/v2.0/me/info*' => Http::response([
                'data' => ['number' => '84988777666'],
                'error' => 0,
            ], 200),
            'https://graph.zalo.me/v2.0/me*' => Http::response([
                'id' => 'zalo_admin_link_1',
                'name' => 'Admin Zalo',
                'error' => 0,
            ], 200),
        ]);

        $this->postJson('/api/v1/auth/zalo-phone', [
            'access_token' => 'zalo-access-token-conflict',
            'phone_token' => 'zalo-phone-token-conflict',
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'ACCOUNT_CONFLICT');
    }

    public function test_login_zalo_phone_ignores_unverified_client_zalo_id_and_blocks_overwriting_existing_phone(): void
    {
        config()->set('services.zalo.app_secret', 'test-zalo-secret-key');

        $victimAdmin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'zalo_user_id' => 'victim_admin_zalo',
            'phone' => '0909998888',
        ]);

        // Phone-only token where /me fails: attacker passes victim's zalo_user_id in body
        Http::fake([
            'https://graph.zalo.me/v2.0/me/info*' => Http::response([
                'data' => ['number' => '84977666555'],
                'error' => 0,
            ], 200),
            'https://graph.zalo.me/v2.0/me*' => Http::response([
                'error' => -452,
                'message' => 'Invalid token for /me',
            ], 200),
        ]);

        $res = $this->postJson('/api/v1/auth/zalo-phone', [
            'access_token' => 'attacker-phone-only-token',
            'phone_token' => 'attacker-phone-token',
            'zalo_user_id' => 'victim_admin_zalo',
        ])->assertOk();

        // Must create a separate customer user for 0977666555, NEVER hijacking $victimAdmin
        $this->assertNotSame($victimAdmin->id, $res->json('data.user.id'));
        $this->assertSame(UserRole::CUSTOMER->value, $res->json('data.user.role'));
        $this->assertSame('0909998888', $victimAdmin->fresh()->phone);
    }

    public function test_login_zalo_phone_blocks_mismatched_phone_for_same_verified_zalo_id(): void
    {
        config()->set('services.zalo.app_secret', 'test-zalo-secret-key');

        User::factory()->create([
            'zalo_user_id' => 'verified_zalo_101',
            'phone' => '0901112222',
        ]);

        Http::fake([
            'https://graph.zalo.me/v2.0/me/info*' => Http::response([
                'data' => ['number' => '84903334444'],
                'error' => 0,
            ], 200),
            'https://graph.zalo.me/v2.0/me*' => Http::response([
                'id' => 'verified_zalo_101',
                'name' => 'Zalo User',
                'error' => 0,
            ], 200),
        ]);

        $this->postJson('/api/v1/auth/zalo-phone', [
            'access_token' => 'valid-zalo-token',
            'phone_token' => 'different-phone-token',
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'ACCOUNT_CONFLICT');
    }

    public function test_login_zalo_phone_fails_closed_when_secret_missing_or_token_invalid(): void
    {
        config()->set('services.zalo.app_secret', '');

        $this->postJson('/api/v1/auth/zalo-phone', [
            'access_token' => 'tok',
            'phone_token' => 'ptok',
        ])->assertStatus(503)
            ->assertJsonPath('error_code', 'ZALO_CONFIG_MISSING');

        config()->set('services.zalo.app_secret', 'valid-secret');
        Http::fake([
            'https://graph.zalo.me/v2.0/me/info*' => Http::response([
                'error' => -124,
                'message' => 'Invalid token',
            ], 200),
        ]);

        $this->postJson('/api/v1/auth/zalo-phone', [
            'access_token' => 'tok',
            'phone_token' => 'expired-ptok',
        ])->assertStatus(401)
            ->assertJsonPath('error_code', 'ZALO_PHONE_TOKEN_INVALID');
    }

    public function test_demo_phone_login_works_in_testing_and_is_blocked_in_production(): void
    {
        config()->set('services.demo.login_enabled', true);

        $this->postJson('/api/v1/auth/demo-phone', [
            'phone_number' => '+84 901-234-567',
            'name' => 'Phụ huynh Demo',
        ])->assertOk()
            ->assertJsonPath('data.user.phone', '0901234567')
            ->assertJsonPath('data.user.has_verified_phone', true);

        // Invalid VN phone returns 422
        $this->postJson('/api/v1/auth/demo-phone', [
            'phone_number' => '012345',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);

        // Disabled flag -> 403
        config()->set('services.demo.login_enabled', false);
        $this->postJson('/api/v1/auth/demo-phone', [
            'phone_number' => '0901234567',
        ])->assertForbidden();

        // Production environment -> 403 even if DEMO_LOGIN_ENABLED=true
        config()->set('services.demo.login_enabled', true);
        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $this->postJson('/api/v1/auth/demo-phone', [
                'phone_number' => '0901234567',
            ])->assertForbidden();
        } finally {
            $this->app['env'] = $originalEnv;
        }
    }
}
