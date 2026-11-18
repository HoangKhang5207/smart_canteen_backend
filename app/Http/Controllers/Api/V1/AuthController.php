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
            'access_token' => ['nullable', 'string', 'max:2048', 'required_without:zalo_user_id'],
            'zalo_user_id' => ['nullable', 'string', 'max:128', 'required_without:access_token'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);

        $accessToken = isset($validated['access_token']) ? trim((string) $validated['access_token']) : '';
        $fallbackZaloId = isset($validated['zalo_user_id']) ? trim((string) $validated['zalo_user_id']) : '';
        $clientName = isset($validated['name']) ? trim((string) $validated['name']) : '';

        $profile = null;

        if ($accessToken !== '') {
            try {
                $profile = $zaloIdentity->verifyAccessToken($accessToken);
            } catch (ZaloAuthException $e) {
                if ($fallbackZaloId === '') {
                    return response()->json([
                        'message' => $e->getMessage(),
                    ], 401);
                }
            }
        }

        $resolvedZaloId = $profile['id'] ?? $fallbackZaloId;
        if ($resolvedZaloId === '') {
            return response()->json([
                'message' => 'Invalid or expired Zalo access token.',
            ], 401);
        }

        $resolvedName = $profile['name'] ?? ($clientName !== '' ? $clientName : 'Khách Zalo');
        if ($resolvedName === 'Khách Zalo' && $clientName !== '') {
            $resolvedName = $clientName;
        }

        $user = User::updateOrCreate(
            ['zalo_user_id' => $resolvedZaloId],
            [
                'name' => $resolvedName,
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
