<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BankLinkStatus;
use App\Enums\SandboxBankMode;
use App\Enums\StudentCardStatus;
use App\Exceptions\CardNotLinkedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSpendingLimitRequest;
use App\Models\SandboxBankAccount;
use App\Models\StudentCard;
use App\Services\SpendingLimitService;
use App\Services\StudentCardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CanteenCardManagementController extends Controller
{
    public function __construct(
        private readonly StudentCardService $studentCardService,
        private readonly SpendingLimitService $spendingLimitService,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $statusFilter = strtoupper(trim((string) $request->query('status', '')));
        $linkFilter = strtolower(trim((string) $request->query('link_status', '')));
        $requestedPerPage = (int) $request->query('per_page', 10);
        $perPage = $requestedPerPage >= 1 && $requestedPerPage <= 100 ? $requestedPerPage : 10;

        $query = StudentCard::query()
            ->with(['student.user', 'activeBankLink', 'spendingLimit'])
            ->orderByDesc('id');

        if ($statusFilter !== '') {
            $parsedStatus = StudentCardStatus::tryFrom($statusFilter);
            if ($parsedStatus !== null) {
                $query->where('status', $parsedStatus->value);
            } else {
                $statusFilter = '';
            }
        }

        if ($linkFilter === 'linked') {
            $query->whereHas('activeBankLink');
        } elseif ($linkFilter === 'unlinked') {
            $query->whereDoesntHave('activeBankLink');
        } else {
            $linkFilter = '';
        }

        if ($search !== '') {
            $needle = '%'.mb_strtolower($search, 'UTF-8').'%';
            $query->where(function ($subQuery) use ($needle): void {
                $subQuery->whereRaw('LOWER(card_code) LIKE ?', [$needle])
                    ->orWhereHas('student', function ($studentQuery) use ($needle): void {
                        $studentQuery->whereRaw('LOWER(full_name) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(class_name) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(student_code) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(school_name) LIKE ?', [$needle]);
                    });
            });
        }

        $cards = $query->paginate($perPage)->withQueryString();

        $limitSummaries = [];
        foreach ($cards as $card) {
            $limitSummaries[$card->id] = $this->spendingLimitService->summarize($card->spendingLimit);
        }

        $totalCards = StudentCard::query()->count();
        $activeCards = StudentCard::query()->where('status', StudentCardStatus::ACTIVE->value)->count();
        $linkedCards = StudentCard::query()->whereHas('activeBankLink')->count();

        return view('admin.canteen.cards.index', [
            'cards' => $cards,
            'limitSummaries' => $limitSummaries,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'linkFilter' => $linkFilter,
            'perPage' => $perPage,
            'stats' => [
                'total_cards' => $totalCards,
                'active_cards' => $activeCards,
                'linked_cards' => $linkedCards,
            ],
        ]);
    }

    public function show(StudentCard $studentCard): View
    {
        $studentCard->load([
            'student.user',
            'activeBankLink',
            'spendingLimit',
        ]);

        $limitSummary = $this->spendingLimitService->summarize($studentCard->spendingLimit);

        $bankLinkHistory = $studentCard->bankLinks()
            ->whereIn('status', [
                BankLinkStatus::ACTIVE->value,
                BankLinkStatus::UNLINKED->value,
                BankLinkStatus::FAILED->value,
                BankLinkStatus::EXPIRED->value,
            ])
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $limitLogs = $studentCard->spendingLimitLogs()
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $recentOrders = $studentCard->orders()
            ->with(['items.product'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        $sandboxControlsEnabled = $this->isSandboxDriverEnabled();
        $sandboxAccount = $sandboxControlsEnabled && $studentCard->activeBankLink?->provider_reference
            ? SandboxBankAccount::query()
                ->where('provider_reference', $studentCard->activeBankLink->provider_reference)
                ->first()
            : null;

        return view('admin.canteen.cards.show', [
            'card' => $studentCard,
            'limitSummary' => $limitSummary,
            'bankLinkHistory' => $bankLinkHistory,
            'limitLogs' => $limitLogs,
            'recentOrders' => $recentOrders,
            'sandboxAccount' => $sandboxAccount,
            'sandboxControlsEnabled' => $sandboxControlsEnabled,
        ]);
    }

    public function updateSandboxAccount(Request $request, StudentCard $studentCard): RedirectResponse
    {
        abort_unless(
            $this->isSandboxDriverEnabled(),
            403,
            'Cấu hình tài khoản Sandbox chỉ khả dụng khi BANKING_GATEWAY_DRIVER=sandbox trong môi trường phát triển/kiểm thử.',
        );

        $validated = $request->validate([
            'balance' => ['required', 'integer', 'min:0', 'max:50000000'],
            'simulated_mode' => ['required', 'string', Rule::enum(SandboxBankMode::class)],
        ]);

        $updated = DB::transaction(function () use ($studentCard, $validated): bool {
            // Enforce canonical lock order: student_cards -> sandbox_bank_accounts
            $lockedCard = StudentCard::query()
                ->whereKey($studentCard->id)
                ->lockForUpdate()
                ->first();

            $activeLink = $lockedCard?->activeBankLink()->first();
            if ($activeLink === null || ! is_string($activeLink->provider_reference) || $activeLink->provider_reference === '') {
                return false;
            }

            SandboxBankAccount::query()
                ->where('provider_reference', $activeLink->provider_reference)
                ->lockForUpdate()
                ->first();

            SandboxBankAccount::updateOrCreate(
                ['provider_reference' => $activeLink->provider_reference],
                [
                    'bank_code' => $activeLink->bank_code,
                    'account_number_masked' => $activeLink->account_number_masked,
                    'balance' => (int) $validated['balance'],
                    'simulated_mode' => SandboxBankMode::from((string) $validated['simulated_mode']),
                ],
            );

            return true;
        });

        if (! $updated) {
            return back()->with('error', 'Thẻ chưa có tài khoản ngân hàng liên kết để cấu hình Sandbox.');
        }

        return back()->with('status', 'Đã cập nhật số dư & chế độ mô phỏng tài khoản Sandbox.');
    }

    private function isSandboxDriverEnabled(): bool
    {
        return strtolower(trim((string) config('services.banking.driver', 'sandbox'))) === 'sandbox'
            && ! app()->isProduction();
    }

    public function updateStatus(Request $request, StudentCard $studentCard): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::enum(StudentCardStatus::class)],
        ]);

        $nextStatus = StudentCardStatus::from((string) $validated['status']);

        try {
            $updated = $this->studentCardService->updateCardStatusByAdmin($studentCard, $nextStatus);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with(
            'status',
            sprintf('Đã cập nhật trạng thái thẻ %s sang %s.', $updated->card_code, $updated->status->value),
        );
    }

    public function updateSpendingLimit(
        UpdateSpendingLimitRequest $request,
        StudentCard $studentCard,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $updated = $this->spendingLimitService->updateDailyLimitByAdmin(
                admin: $request->user(),
                cardId: $studentCard->id,
                newDailyLimit: (int) $validated['daily_limit'],
                reason: isset($validated['reason']) ? (string) $validated['reason'] : null,
            );
        } catch (CardNotLinkedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with(
            'status',
            sprintf(
                'Đã cập nhật hạn mức thẻ %s thành %s đ/ngày.',
                $updated->card_code,
                number_format((int) $validated['daily_limit'], 0, ',', '.'),
            ),
        );
    }
}
