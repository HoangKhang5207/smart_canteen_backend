<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Exceptions\ZaloAuthException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ZaloIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function loginZalo(Request $request, ZaloIdentityService $zaloIdentity): JsonResponse
    {
        $validated = $request->validate([
            'access_token' => ['required', 'string', 'max:2048'],
        ]);

        try {
            $profile = $zaloIdentity->verifyAccessToken($validated['access_token']);
        } catch (ZaloAuthException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 401);
        }

        $user = User::updateOrCreate(
            ['zalo_user_id' => $profile['id']],
            [
                'name' => $profile['name'],
                'role' => UserRole::CUSTOMER,
            ],
        );

        $token = $user->createToken('miniapp')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function loginDemo(Request $request): JsonResponse
    {
        $isAllowedEnv = app()->environment(['local', 'testing']);
        $isEnabled = (bool) config('services.demo.login_enabled', false);

        if (! $isAllowedEnv || ! $isEnabled) {
            return response()->json([
                'message' => 'Demo login is disabled.',
            ], 403);
        }

        $validated = $request->validate([
            'demo_key' => ['nullable', 'string', 'alpha_dash', 'max:32'],
        ]);

        $key = $validated['demo_key'] ?? 'customer1';
        $zaloId = "demo_{$key}";

        $user = User::updateOrCreate(
            ['zalo_user_id' => $zaloId],
            [
                'name' => $key === 'customer1' ? 'Khách hàng Demo' : "Khách Demo ({$key})",
                'role' => UserRole::CUSTOMER,
            ],
        );

        $token = $user->createToken('miniapp-demo')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
