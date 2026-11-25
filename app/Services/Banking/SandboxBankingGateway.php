<?php

namespace App\Services\Banking;

use App\Enums\SandboxBankMode;
use App\Exceptions\InsufficientBankBalanceException;
use App\Exceptions\PaymentGatewayException;
use App\Models\PaymentTransaction;
use App\Models\SandboxBankAccount;
use App\Services\DataMaskingService;
use Illuminate\Support\Str;

class SandboxBankingGateway extends MockEpayBankingGateway
{
    public const DEFAULT_BALANCE_VND = 500_000;

    public function __construct(
        private readonly DataMaskingService $masking,
    ) {}

    public function providerName(): string
    {
        return 'sandbox_epay';
    }

    public function initiateAutoDebitLink(array $payload): array
    {
        $providerRef = 'EPAY-SBX-'.strtoupper($payload['bank_code']).'-'.Str::upper(Str::random(10));
        $accountNumber = trim((string) $payload['account_number']);

        $balance = self::DEFAULT_BALANCE_VND;
        $mode = SandboxBankMode::NORMAL;

        if (str_ends_with($accountNumber, '0000')) {
            $balance = 0;
            $mode = SandboxBankMode::INSUFFICIENT_FUNDS;
        } elseif (str_ends_with($accountNumber, '8888')) {
            $mode = SandboxBankMode::BANK_DECLINED;
        } elseif (str_ends_with($accountNumber, '9999')) {
            $mode = SandboxBankMode::GATEWAY_TIMEOUT;
        }

        SandboxBankAccount::create([
            'provider_reference' => $providerRef,
            'bank_code' => strtoupper($payload['bank_code']),
            'account_number_masked' => $this->masking->maskAccountNumber($accountNumber),
            'balance' => $balance,
            'simulated_mode' => $mode,
        ]);

        return [
            'provider_reference' => $providerRef,
            'otp_channel' => 'SMS_SANDBOX',
        ];
    }

    public function chargeAutoDebit(string $providerReference, int $amountVnd, string $orderCode): array
    {
        /** @var SandboxBankAccount|null $account */
        $account = SandboxBankAccount::query()
            ->where('provider_reference', $providerReference)
            ->lockForUpdate()
            ->first();

        if ($account === null) {
            // Backward compatibility for seeded/test bank links created without initiateAutoDebitLink
            $account = SandboxBankAccount::create([
                'provider_reference' => $providerReference !== '' ? $providerReference : 'EPAY-SBX-AUTO-'.Str::upper(Str::random(8)),
                'bank_code' => 'MB',
                'account_number_masked' => '****0001',
                'balance' => self::DEFAULT_BALANCE_VND,
                'simulated_mode' => SandboxBankMode::NORMAL,
            ]);
        }

        if ($account->simulated_mode === SandboxBankMode::BANK_DECLINED) {
            throw new PaymentGatewayException(
                'Ngân hàng liên kết từ chối giao dịch trích nợ tự động. Vui lòng kiểm tra trạng thái tài khoản ngân hàng.',
                'BANK_DECLINED',
                502,
                'FAILED',
            );
        }

        if ($account->simulated_mode === SandboxBankMode::GATEWAY_TIMEOUT) {
            throw new PaymentGatewayException(
                'Cổng thanh toán ngân hàng phản hồi quá thời gian (timeout). Vui lòng kiểm tra trạng thái giao dịch trước khi thử lại.',
                'GATEWAY_TIMEOUT',
                504,
                'TIMEOUT',
            );
        }

        if ($account->simulated_mode === SandboxBankMode::INSUFFICIENT_FUNDS || (int) $account->balance < $amountVnd) {
            throw new InsufficientBankBalanceException(
                'Tài khoản ngân hàng liên kết không đủ số dư để thực hiện giao dịch này.',
                ['required_amount' => $amountVnd],
            );
        }

        $account->update([
            'balance' => (int) $account->balance - $amountVnd,
        ]);

        return [
            'charged' => true,
            'charge_reference' => 'EPAY-SBX-CHG-'.Str::upper(Str::random(10)),
        ];
    }

    public function refundAutoDebit(string $chargeReference, int $amountVnd, string $orderCode): array
    {
        $chargeTx = PaymentTransaction::query()
            ->with('bankLink')
            ->where('provider_reference', $chargeReference)
            ->where('type', 'CHARGE')
            ->first();

        $bankProviderRef = $chargeTx?->bankLink?->provider_reference;
        if (is_string($bankProviderRef) && $bankProviderRef !== '') {
            /** @var SandboxBankAccount|null $account */
            $account = SandboxBankAccount::query()
                ->where('provider_reference', $bankProviderRef)
                ->lockForUpdate()
                ->first();

            if ($account !== null) {
                $account->update([
                    'balance' => (int) $account->balance + $amountVnd,
                ]);
            }
        }

        return [
            'refunded' => true,
            'refund_reference' => 'EPAY-SBX-REF-'.Str::upper(Str::random(10)),
        ];
    }
}
