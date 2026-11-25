<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationItem;
use App\Services\ReconciliationService;
use App\Services\SpendingLimitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReconciliationController extends Controller
{
    public function __construct(
        private readonly ReconciliationService $reconciliationService,
    ) {}

    public function index(Request $request): View
    {
        $statusFilter = strtoupper(trim((string) $request->query('tx_status', '')));
        $providerFilter = strtolower(trim((string) $request->query('provider', '')));

        $batches = ReconciliationBatch::query()
            ->with(['runner', 'items.resolver'])
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $txQuery = PaymentTransaction::query()
            ->with(['order:id,code', 'studentCard:id,card_code', 'user:id,name'])
            ->orderByDesc('id');

        if (in_array($statusFilter, ['PENDING', 'SUCCEEDED', 'FAILED', 'TIMEOUT'], true)) {
            $txQuery->where('status', $statusFilter);
        } else {
            $statusFilter = '';
        }

        if (in_array($providerFilter, ['sandbox_epay', 'mock_epay', 'vnpay'], true)) {
            $txQuery->where('provider', $providerFilter);
        } else {
            $providerFilter = '';
        }

        return view('admin.canteen.reconciliation.index', [
            'batches' => $batches,
            'transactions' => $txQuery->paginate(15)->withQueryString(),
            'statusFilter' => $statusFilter,
            'providerFilter' => $providerFilter,
            'todayVn' => now(SpendingLimitService::TIMEZONE)->toDateString(),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'provider' => ['nullable', 'string', 'in:sandbox_epay,mock_epay,vnpay'],
        ]);

        $batch = $this->reconciliationService->runForDate(
            date: (string) $validated['date'],
            provider: $validated['provider'] ?? null,
            actor: $request->user(),
        );

        return back()->with(
            'status',
            sprintf(
                'Đã đối soát ngày %s (%s): %d khớp, %d chênh lệch.',
                $batch->reconciliation_date->format('Y-m-d'),
                $batch->provider,
                $batch->matched_count,
                $batch->discrepancy_count,
            ),
        );
    }

    public function resolveItem(Request $request, ReconciliationItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:255'],
        ]);

        $resolutionNote = trim((string) $validated['note']);
        $actorId = $request->user()->id;

        $errorMessage = DB::transaction(function () use ($item, $resolutionNote, $actorId): ?string {
            /** @var ReconciliationBatch $batch */
            $batch = ReconciliationBatch::query()
                ->whereKey($item->batch_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var ReconciliationItem $lockedItem */
            $lockedItem = ReconciliationItem::query()
                ->whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedItem->status === 'MATCHED') {
                return 'Giao dịch đã khớp (MATCHED) không cần xử lý chênh lệch.';
            }

            if ($lockedItem->resolved_at !== null) {
                return sprintf('Chênh lệch đối soát #%d đã được đánh dấu xử lý trước đó.', $lockedItem->id);
            }

            $lockedItem->update([
                'resolution_note' => $resolutionNote,
                'resolved_at' => now(),
                'resolved_by' => $actorId,
            ]);

            $remainingUnresolved = ReconciliationItem::query()
                ->where('batch_id', $batch->id)
                ->where('status', '!=', 'MATCHED')
                ->whereNull('resolved_at')
                ->count();

            if ($remainingUnresolved === 0 && $batch->status !== 'COMPLETED') {
                $batch->update(['status' => 'COMPLETED']);
            }

            return null;
        });

        if ($errorMessage !== null) {
            return back()->with('error', $errorMessage);
        }

        return back()->with('status', sprintf('Đã đánh dấu xử lý chênh lệch đối soát #%d.', $item->id));
    }
}
