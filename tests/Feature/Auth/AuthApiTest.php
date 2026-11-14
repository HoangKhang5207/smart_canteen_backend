<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_zalo_login_verifies_token_with_appsecret_proof_and_returns_sanctum_token(): void
    {
        config(['services.zalo.app_secret' => 'test_zalo_secret_key']);
        $accessToken = 'zalo_access_token_abc123';
        $expectedProof = hash_hmac('sha256', $accessToken, 'test_zalo_secret_key');

        Http::fake([
            'https://graph.zalo.me/*' => function (HttpClientRequest $request) use ($accessToken, $expectedProof) {
                $this->assertSame($accessToken, $request->header('access_token')[0] ?? null);
                $this->assertSame($expectedProof, $request->header('appsecret_proof')[0] ?? null);

                return Http::response([
                    'id' => 'zalo_user_987654',
                    'name' => 'Trần Thị B',
                ], 200);
            },
        ]);

        $response = $this->postJson('/api/v1/auth/zalo', [
            'access_token' => $accessToken,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'user' => ['id', 'name', 'role', 'zalo_user_id'],
                ],
            ])
            ->assertJsonPath('data.user.name', 'Trần Thị B')
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonPath('data.user.zalo_user_id', 'zalo_user_987654');

        $this->assertDatabaseHas('users', [
            'zalo_user_id' => 'zalo_user_987654',
            'name' => 'Trần Thị B',
            'role' => 'customer',
        ]);
    }

    public function test_zalo_login_returns_401_when_zalo_api_rejects_token(): void
    {
        config(['services.zalo.app_secret' => 'test_zalo_secret_key']);

        Http::fake([
            'https://graph.zalo.me/*' => Http::response([
                'error' => -452,
                'message' => 'Access token is invalid',
            ], 200),
        ]);

        $this->postJson('/api/v1/auth/zalo', [
            'access_token' => 'expired_token',
        ])->assertStatus(401);
    }

    public function test_demo_login_succeeds_when_enabled_in_local_or_testing(): void
    {
        config(['services.demo.login_enabled' => true]);

        $response = $this->postJson('/api/v1/auth/demo');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'user' => ['id', 'name', 'role', 'zalo_user_id'],
                ],
            ])
            ->assertJsonPath('data.user.role', 'customer');
    }

    public function test_demo_login_returns_403_when_disabled(): void
    {
        config(['services.demo.login_enabled' => false]);

        $this->postJson('/api/v1/auth/demo')
            ->assertStatus(403);
    }

    public function test_me_endpoint_returns_authenticated_user_and_401_for_guest(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);

        $user = User::factory()->create([
            'name' => 'Khách Zalo',
            'role' => UserRole::CUSTOMER,
            'zalo_user_id' => 'zalo_me_1',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Khách Zalo')
            ->assertJsonPath('data.role', 'customer')
            ->assertJsonPath('data.zalo_user_id', 'zalo_me_1');
    }
}
