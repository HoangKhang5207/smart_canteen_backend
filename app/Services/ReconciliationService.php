<?php

namespace App\Services;

use App\Contracts\BankingGatewayInterface;
use App\Models\PaymentTransaction;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReconciliationService
{
    public const SUPPORTED_PROVIDERS = ['sandbox_epay', 'mock_epay', 'vnpay'];

    public function __construct(
        private readonly BankingGatewayInterface $gateway,
    ) {}

    /**
     * Reconcile local payment_transactions against provider settlement statement for a Vietnam date (YYYY-MM-DD).
     * Idempotent per (reconciliation_date, provider): re-running updates the existing batch while preserving
     * previously resolved discrepancy annotations.
     *
     * @param  list<array{provider_reference: string, order_code?: string, amount: int, status?: string}>|null  $overrideStatement
     */
    public function runForDate(
        string $date,
        ?string $provider = null,
        ?User $actor = null,
        ?array $overrideStatement = null,
    ): ReconciliationBatch {
        $effectiveProvider = $provider !== null && trim($provider) !== ''
            ? strtolower(trim($provider))
            : $this->gateway->providerName();

        if (! in_array($effectiveProvider, self::SUPPORTED_PROVIDERS, true)) {
            throw new InvalidArgumentException(sprintf('Cổng thanh toán không được hỗ trợ đối soát: %s.', $effectiveProvider));
        }

        $startUtc = Carbon::parse($date, SpendingLimitService::TIMEZONE)->startOfDay()->utc();
        $endUtc = Carbon::parse($date, SpendingLimitService::TIMEZONE)->endOfDay()->utc();

        $statement = $overrideStatement ?? $this->fetchStatementForProvider($date, $effectiveProvider, $startUtc, $endUtc);

        return DB::transaction(function () use (
            $date,
            $effectiveProvider,
            $actor,
            $startUtc,
            $endUtc,
            $statement,
        ): ReconciliationBatch {
            $localTxs = PaymentTransaction::query()
                ->with('order:id,code')
                ->where('provider', $effectiveProvider)
                ->where('type', 'CHARGE')
                ->whereBetween('created_at', [$startUtc, $endUtc])
                ->orderBy('id')
                ->get();

            $statementByRef = [];
            foreach ($statement as $row) {
                $ref = trim((string) ($row['provider_reference'] ?? ''));
                if ($ref !== '') {
                    $statementByRef[$ref] = $row;
                }
            }

            $seenProviderRefs = [];
            $itemsPayload = [];
            $matchedCount = 0;
            $discrepancyCount = 0;
            $totalLocalAmount = 0;
            $totalProviderAmount = 0;

            foreach ($statementByRef as $row) {
                $totalProviderAmount += (int) ($row['amount'] ?? 0);
            }

            foreach ($localTxs as $tx) {
                $localRef = is_string($tx->provider_reference) ? trim($tx->provider_reference) : '';
                $orderCode = $tx->order?->code ?? $tx->gateway_txn_ref;

                if ($tx->status === 'SUCCEEDED') {
                    $totalLocalAmount += (int) $tx->amount;
                }

                // Pure failed local attempts with no provider reference are only discrepancies if present in bank
                if ($localRef === '' && $tx->status === 'FAILED') {
                    continue;
                }

                if ($localRef !== '' && isset($statementByRef[$localRef])) {
                    $seenProviderRefs[$localRef] = true;
                    $bankRow = $statementByRef[$localRef];
                    $bankAmount = (int) ($bankRow['amount'] ?? 0);
                    $bankStatus = (string) ($bankRow['status'] ?? 'SUCCEEDED');

                    if ($tx->status !== $bankStatus) {
                        $status = 'STATUS_MISMATCH';
                        $note = sprintf('Trạng thái nội bộ (%s) lệch với ngân hàng (%s).', $tx->status, $bankStatus);
                        $discrepancyCount++;
                    } elseif ((int) $tx->amount !== $bankAmount) {
                        $status = 'AMOUNT_MISMATCH';
                        $note = sprintf('Số tiền nội bộ (%d đ) lệch với sao kê ngân hàng (%d đ).', $tx->amount, $bankAmount);
                        $discrepancyCount++;
                    } else {
                        $status = 'MATCHED';
                        $note = null;
                        $matchedCount++;
                    }

                    $itemsPayload[] = [
                        'payment_transaction_id' => $tx->id,
                        'provider_reference' => $localRef,
                        'order_code' => $orderCode ?? ($bankRow['order_code'] ?? null),
                        'local_amount' => (int) $tx->amount,
                        'provider_amount' => $bankAmount,
                        'status' => $status,
                        'note' => $note,
                    ];
                } elseif ($tx->status === 'SUCCEEDED' || $tx->status === 'TIMEOUT') {
                    $discrepancyCount++;
                    $itemsPayload[] = [
                        'payment_transaction_id' => $tx->id,
                        'provider_reference' => $localRef !== '' ? $localRef : null,
                        'order_code' => $orderCode,
                        'local_amount' => (int) $tx->amount,
                        'provider_amount' => null,
                        'status' => $tx->status === 'TIMEOUT' ? 'STATUS_MISMATCH' : 'MISSING_IN_BANK',
                        'note' => $tx->status === 'TIMEOUT'
                            ? 'Giao dịch nội bộ đang ở trạng thái TIMEOUT, chưa thấy trong sao kê ngân hàng.'
                            : 'Giao dịch thành công nội bộ nhưng thiếu trong sao kê ngân hàng.',
                    ];
                }
            }

            foreach ($statementByRef as $ref => $bankRow) {
                if (isset($seenProviderRefs[$ref])) {
                    continue;
                }
                $discrepancyCount++;
                $itemsPayload[] = [
                    'payment_transaction_id' => null,
                    'provider_reference' => $ref,
                    'order_code' => $bankRow['order_code'] ?? null,
                    'local_amount' => null,
                    'provider_amount' => (int) ($bankRow['amount'] ?? 0),
                    'status' => 'MISSING_LOCAL',
                    'note' => 'Giao dịch có trong sao kê ngân hàng nhưng không tồn tại ở sổ cái nội bộ.',
                ];
            }

            /** @var ReconciliationBatch|null $existingBatch */
            $existingBatch = ReconciliationBatch::query()
                ->where('reconciliation_date', $date)
                ->where('provider', $effectiveProvider)
                ->lockForUpdate()
                ->first();

            $preservedResolutions = [];
            if ($existingBatch !== null) {
                $oldResolvedItems = ReconciliationItem::query()
                    ->where('batch_id', $existingBatch->id)
                    ->whereNotNull('resolved_at')
                    ->get();

                foreach ($oldResolvedItems as $oldItem) {
                    $resolutionData = [
                        'resolved_at' => $oldItem->resolved_at,
                        'resolved_by' => $oldItem->resolved_by,
                        'resolution_note' => $oldItem->resolution_note,
                    ];
                    if ($oldItem->payment_transaction_id !== null) {
                        $preservedResolutions['tx:'.$oldItem->payment_transaction_id] = $resolutionData;
                    }
                    if (is_string($oldItem->provider_reference) && $oldItem->provider_reference !== '') {
                        $preservedResolutions['ref:'.$oldItem->provider_reference] = $resolutionData;
                    }
                }

                ReconciliationItem::query()->where('batch_id', $existingBatch->id)->delete();
            }

            $unresolvedDiscrepancies = 0;
            foreach ($itemsPayload as &$row) {
                $resolution = null;
                if ($row['status'] !== 'MATCHED') {
                    if ($row['payment_transaction_id'] !== null && isset($preservedResolutions['tx:'.$row['payment_transaction_id']])) {
                        $resolution = $preservedResolutions['tx:'.$row['payment_transaction_id']];
                    } elseif (is_string($row['provider_reference']) && isset($preservedResolutions['ref:'.$row['provider_reference']])) {
                        $resolution = $preservedResolutions['ref:'.$row['provider_reference']];
                    }

                    if ($resolution === null) {
                        $unresolvedDiscrepancies++;
                    }
                }

                $row['resolution_note'] = $resolution['resolution_note'] ?? null;
                $row['resolved_at'] = $resolution['resolved_at'] ?? null;
                $row['resolved_by'] = $resolution['resolved_by'] ?? null;
            }
            unset($row);

            $batchAttributes = [
                'status' => $unresolvedDiscrepancies > 0 ? 'HAS_DISCREPANCIES' : 'COMPLETED',
                'total_local_count' => $localTxs->where('status', 'SUCCEEDED')->count(),
                'total_provider_count' => count($statementByRef),
                'matched_count' => $matchedCount,
                'discrepancy_count' => $discrepancyCount,
                'total_local_amount' => $totalLocalAmount,
                'total_provider_amount' => $totalProviderAmount,
                'run_by' => $actor?->id,
                'completed_at' => now(),
            ];

            if ($existingBatch !== null) {
                $existingBatch->update($batchAttributes);
                $batch = $existingBatch;
            } else {
                $batch = ReconciliationBatch::create(array_merge([
                    'reconciliation_date' => $date,
                    'provider' => $effectiveProvider,
                ], $batchAttributes));
            }

            foreach ($itemsPayload as $row) {
                ReconciliationItem::create(array_merge($row, [
                    'batch_id' => $batch->id,
                ]));
            }

            return $batch->load(['items', 'runner']);
        });
    }

    /**
     * @return list<array{provider_reference: string, order_code?: string, amount: int, status?: string}>
     */
    private function fetchStatementForProvider(
        string $date,
        string $provider,
        Carbon $startUtc,
        Carbon $endUtc,
    ): array {
        if ($provider === $this->gateway->providerName()) {
            return $this->gateway->fetchSettlementStatement($date);
        }

        // Sandbox/simulated settlement statement for secondary providers (vnpay, mock_epay)
        return PaymentTransaction::query()
            ->with('order:id,code')
            ->where('provider', $provider)
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
