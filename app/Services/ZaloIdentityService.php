<?php

namespace App\Services;

use App\Exceptions\ZaloAuthException;
use Illuminate\Support\Facades\Http;
use Throwable;

class ZaloIdentityService
{
    public function __construct(
        private readonly PhoneNormalizer $phoneNormalizer,
    ) {}

    /**
     * Resolve user phone number from Zalo Mini App getPhoneNumber() one-time token via server-to-server Graph API.
     */
    public function resolvePhoneNumber(string $accessToken, string $phoneToken): string
    {
        $secret = trim((string) config('services.zalo.app_secret', ''));
        if ($secret === '') {
            throw new ZaloAuthException(
                'Hệ thống chưa cấu hình ZALO_APP_SECRET để xác thực số điện thoại Zalo.',
                503,
                'ZALO_CONFIG_MISSING',
            );
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'access_token' => $accessToken,
                    'code' => $phoneToken,
                    'secret_key' => $secret,
                ])
                ->get('https://graph.zalo.me/v2.0/me/info');
        } catch (Throwable) {
            throw new ZaloAuthException(
                'Không thể kết nối tới Zalo Open API để xác thực số điện thoại.',
                503,
                'ZALO_UNAVAILABLE',
            );
        }

        if ($response->serverError()) {
            throw new ZaloAuthException(
                'Zalo Open API tạm thời không phản hồi. Vui lòng thử lại sau.',
                503,
                'ZALO_UNAVAILABLE',
            );
        }

        if (! $response->successful()) {
            throw new ZaloAuthException(
                'Mã xác thực số điện thoại Zalo không hợp lệ hoặc đã hết hạn.',
                401,
                'ZALO_PHONE_TOKEN_INVALID',
            );
        }

        $payload = $response->json();
        $errorCode = is_array($payload) && isset($payload['error']) ? (int) $payload['error'] : -1;
        $rawNumber = is_array($payload) ? (string) ($payload['data']['number'] ?? '') : '';

        if ($errorCode !== 0 || $rawNumber === '') {
            throw new ZaloAuthException(
                'Mã xác thực số điện thoại Zalo không hợp lệ hoặc đã hết hạn.',
                401,
                'ZALO_PHONE_TOKEN_INVALID',
            );
        }

        $normalized = $this->phoneNormalizer->normalizeVietnamMobile($rawNumber);
        if ($normalized === null) {
            throw new ZaloAuthException(
                'Số điện thoại từ Zalo không đúng định dạng di động Việt Nam hợp lệ.',
                422,
                'INVALID_PHONE_NUMBER',
            );
        }

        return $normalized;
    }

    /**
     * @return array{id: string, name: string}
     */
    public function verifyAccessToken(string $accessToken): array
    {
        $secret = trim((string) config('services.zalo.app_secret', ''));
        $appId = trim((string) config('services.zalo.app_id', ''));

        $attempts = [];

        if ($secret !== '') {
            $attempts[] = [
                'headers' => [
                    'access_token' => $accessToken,
                    'appsecret_proof' => hash_hmac('sha256', $accessToken, $secret),
                ],
                'query' => [
                    'fields' => 'id,name,picture',
                ],
            ];
        }

        // Fallback for Zalo Mini App tokens where appsecret_proof is not enabled or uses miniapp_id
        $fallbackQuery = ['fields' => 'id,name,picture'];
        if ($appId !== '') {
            $fallbackQuery['miniapp_id'] = $appId;
        }
        $attempts[] = [
            'headers' => [
                'access_token' => $accessToken,
            ],
            'query' => $fallbackQuery,
        ];

        // Fallback for Zalo Mini App SDK 2.35.0+ default tokens that only have permission for `id`
        $idOnlyQuery = ['fields' => 'id'];
        if ($appId !== '') {
            $idOnlyQuery['miniapp_id'] = $appId;
        }
        $attempts[] = [
            'headers' => [
                'access_token' => $accessToken,
            ],
            'query' => $idOnlyQuery,
        ];

        $hadNetworkResponse = false;

        foreach ($attempts as $attempt) {
            try {
                $response = Http::timeout(10)
                    ->withHeaders($attempt['headers'])
                    ->get('https://graph.zalo.me/v2.0/me', $attempt['query']);
            } catch (Throwable $e) {
                continue;
            }

            $hadNetworkResponse = true;

            if (! $response->successful()) {
                continue;
            }

            $payload = $response->json();
            if (is_array($payload) && (! isset($payload['error']) || (int) $payload['error'] === 0) && ! empty($payload['id'])) {
                return [
                    'id' => (string) $payload['id'],
                    'name' => ! empty($payload['name']) ? (string) $payload['name'] : 'Khách Zalo',
                ];
            }

            // If token itself is explicitly invalid/expired (-452), stop retrying immediately
            if (is_array($payload) && isset($payload['error']) && (int) $payload['error'] === -452) {
                throw new ZaloAuthException('Invalid or expired Zalo access token.');
            }
        }

        if (! $hadNetworkResponse) {
            throw new ZaloAuthException('Unable to reach Zalo Open API.');
        }

        throw new ZaloAuthException('Invalid or expired Zalo access token.');
    }
}
