<?php

namespace App\Services\Banking;

use App\Contracts\BankingGatewayInterface;
use App\Models\PaymentTransaction;
use App\Services\SpendingLimitService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MockEpayBankingGateway implements BankingGatewayInterface
{
    /**
     * @var list<array{
     *     code: string,
     *     name: string,
     *     short_name: string,
     *     account_min_length: int,
     *     account_max_length: int
     * }>
     */
    private const SUPPORTED_BANKS = [
        [
            'code' => 'MB',
            'name' => 'Ngân hàng TMCP Quân đội (MB Bank)',
            'short_name' => 'MB Bank',
            'account_min_length' => 6,
            'account_max_length' => 19,
        ],
        [
            'code' => 'VCB',
            'name' => 'Ngân hàng TMCP Ngoại thương Việt Nam (Vietcombank)',
            'short_name' => 'Vietcombank',
            'account_min_length' => 10,
            'account_max_length' => 16,
        ],
        [
            'code' => 'BIDV',
            'name' => 'Ngân hàng TMCP Đầu tư và Phát triển Việt Nam (BIDV)',
            'short_name' => 'BIDV',
            'account_min_length' => 10,
            'account_max_length' => 16,
        ],
        [
            'code' => 'TCB',
            'name' => 'Ngân hàng TMCP Kỹ thương Việt Nam (Techcombank)',
            'short_name' => 'Techcombank',
            'account_min_length' => 10,
            'account_max_length' => 16,
        ],
        [
            'code' => 'CTG',
            'name' => 'Ngân hàng TMCP Công thương Việt Nam (VietinBank)',
            'short_name' => 'VietinBank',
            'account_min_length' => 10,
            'account_max_length' => 16,
        ],
        [
            'code' => 'ACB',
            'name' => 'Ngân hàng TMCP Á Châu (ACB)',
            'short_name' => 'ACB',
            'account_min_length' => 8,
            'account_max_length' => 16,
        ],
        [
            'code' => 'VPB',
            'name' => 'Ngân hàng TMCP Việt Nam Thịnh Vượng (VPBank)',
            'short_name' => 'VPBank',
            'account_min_length' => 8,
            'account_max_length' => 16,
        ],
    ];

    public function listSupportedBanks(): array
    {
        return self::SUPPORTED_BANKS;
    }

    public function findBank(string $bankCode): ?array
    {
        $normalized = strtoupper(trim($bankCode));

        foreach (self::SUPPORTED_BANKS as $bank) {
            if ($bank['code'] === $normalized) {
                return $bank;
            }
        }

        return null;
    }

    public function initiateAutoDebitLink(array $payload): array
    {
        return [
            'provider_reference' => 'EPAY-MOCK-'.strtoupper($payload['bank_code']).'-'.Str::upper(Str::random(10)),
            'otp_channel' => 'SMS_MOCK',
        ];
    }

    public function confirmAutoDebitLink(string $providerReference): array
    {
        return [
            'confirmed' => true,
            'provider_reference' => $providerReference,
        ];
    }

    public function unlinkAutoDebit(string $providerReference): array
    {
        return [
            'unlinked' => true,
            'provider_reference' => $providerReference,
        ];
    }

    public function chargeAutoDebit(string $providerReference, int $amountVnd, string $orderCode): array
    {
        return [
            'charged' => true,
            'charge_reference' => 'EPAY-CHG-'.Str::upper(Str::random(10)),
        ];
    }

    public function refundAutoDebit(string $chargeReference, int $amountVnd, string $orderCode): array
    {
        return [
            'refunded' => true,
            'refund_reference' => 'EPAY-REF-'.Str::upper(Str::random(10)),
        ];
    }

    public function providerName(): string
    {
        return 'mock_epay';
    }

    public function fetchSettlementStatement(string $date): array
    {
        $startUtc = Carbon::parse($date, SpendingLimitService::TIMEZONE)->startOfDay()->utc();
        $endUtc = Carbon::parse($date, SpendingLimitService::TIMEZONE)->endOfDay()->utc();

        return PaymentTransaction::query()
            ->with('order:id,code')
            ->where('provider', $this->providerName())
            ->where('type', 'CHARGE')
            ->where('status', 'SUCCEEDED')
            ->whereNotNull('provider_reference')
            ->whereBetween('created_at', [$startUtc, $endUtc])
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentTransaction $tx): array => [
                'provider_reference' => (string) $tx->provider_reference,
                'order_code' => (string) ($tx->order?->code ?? $tx->gateway_txn_ref ?? ''),
                'amount' => (int) $tx->amount,
                'status' => 'SUCCEEDED',
            ])
            ->values()
            ->all();
    }
}
