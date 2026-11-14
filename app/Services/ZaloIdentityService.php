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
        $secret = (string) config('services.zalo.app_secret', '');
        if ($secret === '') {
            throw new ZaloAuthException('Zalo App Secret is not configured.');
        }

        $appSecretProof = hash_hmac('sha256', $accessToken, $secret);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'access_token' => $accessToken,
                    'appsecret_proof' => $appSecretProof,
                ])
                ->get('https://graph.zalo.me/v2.0/me', [
                    'fields' => 'id,name,picture',
                ]);
        } catch (Throwable $e) {
            throw new ZaloAuthException('Unable to reach Zalo Open API.', 0, $e);
        }

        if (! $response->successful()) {
            throw new ZaloAuthException('Invalid or expired Zalo access token.');
        }

        $payload = $response->json();
        if (! is_array($payload) || isset($payload['error']) || empty($payload['id'])) {
            throw new ZaloAuthException('Invalid or expired Zalo access token.');
        }

        return [
            'id' => (string) $payload['id'],
            'name' => ! empty($payload['name']) ? (string) $payload['name'] : 'Khách Zalo',
        ];
    }
}
