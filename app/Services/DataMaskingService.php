<?php

namespace App\Services;

class DataMaskingService
{
    public function maskAccountNumber(string $accountNumber): string
    {
        $digits = preg_replace('/\D+/', '', $accountNumber) ?? '';
        $tail = substr($digits, -4);

        return '****'.$tail;
    }

    public function maskNationalId(string $nationalId): string
    {
        $digits = preg_replace('/\D+/', '', $nationalId) ?? '';
        $tail = substr($digits, -4);
        $maskLength = max(4, strlen($digits) - strlen($tail));

        return str_repeat('*', $maskLength).$tail;
    }

    public function maskPhoneNumber(string $phoneNumber): string
    {
        $digits = preg_replace('/\D+/', '', $phoneNumber) ?? '';

        if (strlen($digits) < 7) {
            return str_repeat('*', max(0, strlen($digits) - 3)).substr($digits, -3);
        }

        return substr($digits, 0, 3).'***'.substr($digits, -4);
    }
}
