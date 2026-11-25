<?php

namespace App\Console\Commands;

use App\Services\ReconciliationService;
use App\Services\SpendingLimitService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ReconcileCanteenPaymentsCommand extends Command
{
    protected $signature = 'canteen:reconcile {date? : Vietnam calendar date YYYY-MM-DD (defaults to today)} {--provider= : Provider name (sandbox_epay, mock_epay, vnpay)}';

    protected $description = 'Run bank settlement reconciliation for Smart Canteen payment transactions';

    public function handle(ReconciliationService $reconciliationService): int
    {
        $rawDate = $this->argument('date');
        $date = is_string($rawDate) && trim($rawDate) !== ''
            ? trim($rawDate)
            : now(SpendingLimitService::TIMEZONE)->toDateString();

        try {
            $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
                ? Carbon::createFromFormat('Y-m-d', $date)
                : null;
        } catch (\Throwable) {
            $parsed = null;
        }

        if ($parsed === null || $parsed === false || $parsed->format('Y-m-d') !== $date) {
            $this->error(sprintf('Ngày đối soát không hợp lệ: "%s". Vui lòng dùng định dạng YYYY-MM-DD.', $date));

            return self::FAILURE;
        }

        $rawProvider = $this->option('provider');
        $provider = is_string($rawProvider) && trim($rawProvider) !== '' ? strtolower(trim($rawProvider)) : null;

        if ($provider !== null && ! in_array($provider, ReconciliationService::SUPPORTED_PROVIDERS, true)) {
            $this->error(sprintf(
                'Cổng thanh toán không hợp lệ: "%s". Giá trị hợp lệ: %s.',
                $provider,
                implode(', ', ReconciliationService::SUPPORTED_PROVIDERS),
            ));

            return self::FAILURE;
        }

        $batch = $reconciliationService->runForDate($date, $provider);

        $this->info(sprintf(
            'Reconciliation #%d (%s | %s): %s — Matched: %d, Discrepancies: %d.',
            $batch->id,
            $batch->reconciliation_date->format('Y-m-d'),
            $batch->provider,
            $batch->status,
            $batch->matched_count,
            $batch->discrepancy_count,
        ));

        return self::SUCCESS;
    }
}
