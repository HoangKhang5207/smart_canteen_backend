<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Exceptions\ZaloAuthException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\PhoneNormalizer;
use App\Services\ZaloIdentityService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        $resolveUser = function () use ($resolvedZaloId, $resolvedName): User {
            return DB::transaction(function () use ($resolvedZaloId, $resolvedName): User {
                /** @var User|null $existing */
                $existing = User::query()
                    ->where('zalo_user_id', $resolvedZaloId)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    // Never overwrite existing user's role (preserves student/parent/staff/admin RBAC)
                    if (in_array($existing->name, ['Khách Zalo', 'Phụ huynh Zalo', ''], true) && $resolvedName !== 'Khách Zalo') {
                        $existing->update(['name' => $resolvedName]);
                    }

                    return $existing->fresh();
                }

                return User::create([
                    'zalo_user_id' => $resolvedZaloId,
                    'name' => $resolvedName,
                    'role' => UserRole::CUSTOMER,
                ]);
            });
        };

        try {
            $user = $resolveUser();
        } catch (UniqueConstraintViolationException) {
            $user = $resolveUser();
        }

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
        $defaultName = $key === 'customer1' ? 'Khách hàng Demo' : "Khách Demo ({$key})";

        $resolveDemo = function () use ($zaloId, $defaultName): User {
            return DB::transaction(function () use ($zaloId, $defaultName): User {
                /** @var User|null $existing */
                $existing = User::query()
                    ->where('zalo_user_id', $zaloId)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    // Preserve existing role (e.g., demo_student1 stays UserRole::STUDENT)
                    return $existing;
                }

                return User::create([
                    'zalo_user_id' => $zaloId,
                    'name' => $defaultName,
                    'role' => UserRole::CUSTOMER,
                ]);
            });
        };

        try {
            $user = $resolveDemo();
        } catch (UniqueConstraintViolationException) {
            $user = $resolveDemo();
        }

        $token = $user->createToken('miniapp-demo')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function loginZaloPhone(
        Request $request,
        ZaloIdentityService $zaloIdentity,
    ): JsonResponse {
        $validated = $request->validate([
            'access_token' => ['required', 'string', 'max:2048'],
            'phone_token' => ['required', 'string', 'max:2048'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);

        $accessToken = trim((string) $validated['access_token']);
        $phoneToken = trim((string) $validated['phone_token']);
        $clientName = isset($validated['name']) ? trim((string) $validated['name']) : '';

        try {
            $phone = $zaloIdentity->resolvePhoneNumber($accessToken, $phoneToken);
        } catch (ZaloAuthException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], $e->statusCode);
        }

        // Security: only accept server-verified Zalo user ID (never trust client-supplied zalo_user_id)
        $profile = null;
        try {
            $profile = $zaloIdentity->verifyAccessToken($accessToken);
        } catch (ZaloAuthException) {
            // Token may only hold phone scope; resolve strictly by server-verified phone number
        }

        $resolvedZaloId = $profile['id'] ?? null;
        $resolvedName = $profile['name'] ?? ($clientName !== '' ? $clientName : 'Phụ huynh Zalo');
        if ($resolvedName === 'Khách Zalo' && $clientName !== '') {
            $resolvedName = $clientName;
        }

        $resolveTransaction = function () use ($phone, $resolvedZaloId, $resolvedName): array {
            return DB::transaction(function () use ($phone, $resolvedZaloId, $resolvedName): array {
                // Canonical lock order: lock matching users sorted by id ASC
                $candidates = User::query()
                    ->where(function ($query) use ($phone, $resolvedZaloId): void {
                        $query->where('phone', $phone);
                        if ($resolvedZaloId !== null) {
                            $query->orWhere('zalo_user_id', $resolvedZaloId);
                        }
                    })
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                /** @var User|null $userByPhone */
                $userByPhone = $candidates->firstWhere('phone', $phone);
                /** @var User|null $userByZalo */
                $userByZalo = $resolvedZaloId !== null
                    ? $candidates->firstWhere('zalo_user_id', $resolvedZaloId)
                    : null;

                if ($userByPhone !== null && $userByZalo !== null && $userByPhone->id !== $userByZalo->id) {
                    return ['conflict' => true, 'user' => null];
                }

                // Never silently overwrite a different verified phone already attached to this Zalo ID
                if ($userByPhone === null && $userByZalo !== null && $userByZalo->phone !== null && $userByZalo->phone !== $phone) {
                    return ['conflict' => true, 'user' => null];
                }

                $now = now();

                if ($userByPhone !== null) {
                    $updates = ['phone_verified_at' => $now];
                    if ($userByPhone->zalo_user_id === null && $resolvedZaloId !== null) {
                        $updates['zalo_user_id'] = $resolvedZaloId;
                    }
                    if (in_array($userByPhone->name, ['Khách Zalo', 'Phụ huynh Zalo', ''], true) && $resolvedName !== 'Phụ huynh Zalo') {
                        $updates['name'] = $resolvedName;
                    }
                    $userByPhone->update($updates);

                    return ['conflict' => false, 'user' => $userByPhone->fresh()];
                }

                if ($userByZalo !== null) {
                    $updates = [
                        'phone' => $phone,
                        'phone_verified_at' => $now,
                    ];
                    if (in_array($userByZalo->name, ['Khách Zalo', 'Phụ huynh Zalo', ''], true) && $resolvedName !== 'Phụ huynh Zalo') {
                        $updates['name'] = $resolvedName;
                    }
                    $userByZalo->update($updates);

                    return ['conflict' => false, 'user' => $userByZalo->fresh()];
                }

                $created = User::create([
                    'name' => $resolvedName,
                    'role' => UserRole::CUSTOMER,
                    'zalo_user_id' => $resolvedZaloId,
                    'phone' => $phone,
                    'phone_verified_at' => $now,
                ]);

                return ['conflict' => false, 'user' => $created];
            });
        };

        try {
            $resolution = $resolveTransaction();
        } catch (UniqueConstraintViolationException) {
            // Concurrent first-time login race: retry once to resolve the newly committed row
            $resolution = $resolveTransaction();
        }

        if ($resolution['conflict']) {
            return response()->json([
                'message' => 'Tài khoản Zalo này đang liên kết với một số điện thoại khác. Vui lòng liên hệ nhà trường để được hỗ trợ.',
                'error_code' => 'ACCOUNT_CONFLICT',
            ], 409);
        }

        /** @var User $user */
        $user = $resolution['user'];
        $token = $user->createToken('miniapp-phone')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function loginDemoPhone(
        Request $request,
        PhoneNormalizer $phoneNormalizer,
    ): JsonResponse {
        $isAllowedEnv = app()->environment(['local', 'testing']);
        $isEnabled = (bool) config('services.demo.login_enabled', false);

        if (! $isAllowedEnv || ! $isEnabled) {
            return response()->json([
                'message' => 'Demo phone login is disabled.',
            ], 403);
        }

        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:24'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);

        $normalizedPhone = $phoneNormalizer->normalizeVietnamMobile((string) $validated['phone_number']);
        if ($normalizedPhone === null) {
            throw ValidationException::withMessages([
                'phone_number' => ['Số điện thoại không hợp lệ (cần đúng 10 chữ số di động Việt Nam, ví dụ 0901234567).'],
            ]);
        }

        $clientName = isset($validated['name']) && trim((string) $validated['name']) !== ''
            ? trim((string) $validated['name'])
            : null;

        $resolveDemoUser = function () use ($normalizedPhone, $clientName): User {
            return DB::transaction(function () use ($normalizedPhone, $clientName): User {
                /** @var User|null $existing */
                $existing = User::query()
                    ->where('phone', $normalizedPhone)
                    ->lockForUpdate()
                    ->first();

                $now = now();
                if ($existing !== null) {
                    $updates = ['phone_verified_at' => $now];
                    if ($clientName !== null && in_array($existing->name, ['Khách Zalo', 'Phụ huynh Zalo', ''], true)) {
                        $updates['name'] = $clientName;
                    }
                    $existing->update($updates);

                    return $existing->fresh();
                }

                return User::create([
                    'name' => $clientName ?? 'Phụ huynh ('.$normalizedPhone.')',
                    'role' => UserRole::CUSTOMER,
                    'zalo_user_id' => 'demo_phone_'.$normalizedPhone,
                    'phone' => $normalizedPhone,
                    'phone_verified_at' => $now,
                ]);
            });
        };

        try {
            $user = $resolveDemoUser();
        } catch (UniqueConstraintViolationException) {
            $user = $resolveDemoUser();
        }

        $token = $user->createToken('miniapp-demo-phone')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Đã đăng xuất thành công.',
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
