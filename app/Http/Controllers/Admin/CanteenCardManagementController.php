<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BankLinkStatus;
use App\Enums\StudentCardStatus;
use App\Exceptions\CardNotLinkedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSpendingLimitRequest;
use App\Models\StudentCard;
use App\Services\SpendingLimitService;
use App\Services\StudentCardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('admin.canteen.cards.show', [
            'card' => $studentCard,
            'limitSummary' => $limitSummary,
            'bankLinkHistory' => $bankLinkHistory,
            'limitLogs' => $limitLogs,
            'recentOrders' => $recentOrders,
        ]);
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
