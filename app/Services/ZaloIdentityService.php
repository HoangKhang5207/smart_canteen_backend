<?php

namespace App\Services;

use App\Exceptions\ZaloAuthException;
use Illuminate\Support\Facades\Http;
use Throwable;

class ZaloIdentityService
{
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
