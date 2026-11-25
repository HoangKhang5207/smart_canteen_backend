<?php

namespace App\Services;

class PhoneNormalizer
{
    public const VIETNAM_MOBILE_REGEX = '/^0[35789]\d{8}$/';

    public function normalizeVietnamMobile(string $raw): ?string
    {
        $cleaned = preg_replace('/[\s.\-()]+/', '', trim($raw)) ?? '';
        if ($cleaned === '') {
            return null;
        }

        if (str_starts_with($cleaned, '+84')) {
            $cleaned = '0'.substr($cleaned, 3);
        } elseif (str_starts_with($cleaned, '84') && strlen($cleaned) === 11) {
            $cleaned = '0'.substr($cleaned, 2);
        }

        if (preg_match(self::VIETNAM_MOBILE_REGEX, $cleaned) !== 1) {
            return null;
        }

        return $cleaned;
    }

    public function toE164(string $normalizedVietnamPhone): string
    {
        $normalized = $this->normalizeVietnamMobile($normalizedVietnamPhone) ?? trim($normalizedVietnamPhone);
        if (str_starts_with($normalized, '0')) {
            return '+84'.substr($normalized, 1);
        }

        return str_starts_with($normalized, '+') ? $normalized : '+'.$normalized;
    }
}
