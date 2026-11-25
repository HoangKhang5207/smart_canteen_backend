<?php

namespace App\Services;

use App\Contracts\BankingGatewayInterface;
use App\Enums\BankLinkStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StudentCardStatus;
use App\Exceptions\CardNotLinkedException;
use App\Exceptions\SpendingLimitExceededException;
use App\Models\BankLink;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\SandboxBankAccount;
use App\Models\SpendingLimit;
use App\Models\SpendingLimitLog;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SpendingLimitService
{
    public const TIMEZONE = 'Asia/Ho_Chi_Minh';

    public function __construct(
        private readonly BankingGatewayInterface $gateway,
        private readonly PaymentLedgerService $ledger,
    ) {}

    public function gatewayProviderName(): string
    {
        return $this->gateway->providerName();
    }

    public function todayDate(): string
    {
        return now(self::TIMEZONE)->toDateString();
    }

    /**
     * @return array{daily_limit: int, spent_today: int, remaining_limit: int, effective_date: string}
     */
    public function summarize(?SpendingLimit $limit): array
    {
        $today = $this->todayDate();
        $dailyLimit = $limit !== null ? (int) $limit->daily_limit : 0;
        $spentToday = ($limit !== null && (string) $limit->spent_date === $today)
            ? (int) $limit->spent_today
            : 0;

        return [
            'daily_limit' => $dailyLimit,
            'spent_today' => $spentToday,
            'remaining_limit' => max(0, $dailyLimit - $spentToday),
            'effective_date' => $today,
        ];
    }

    public function updateDailyLimit(
        User $user,
        int $cardId,
        int $newDailyLimit,
        ?string $reason = null,
    ): StudentCard {
        $normalizedReason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        $today = $this->todayDate();

        return DB::transaction(function () use ($user, $cardId, $newDailyLimit, $normalizedReason, $today): StudentCard {
            /** @var StudentCard|null $card */
            $card = StudentCard::query()
                ->whereKey($cardId)
                ->whereHas('student', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true))
                ->lockForUpdate()
                ->first();

            if ($card === null) {
                throw (new ModelNotFoundException)->setModel(StudentCard::class, [$cardId]);
            }

            if ($card->status !== StudentCardStatus::ACTIVE) {
                throw ValidationException::withMessages([
                    'card' => ['Thẻ học sinh hiện không ở trạng thái hoạt động.'],
                ]);
            }

            $hasActiveLink = BankLink::query()
                ->where('student_card_id', $card->id)
                ->where('status', BankLinkStatus::ACTIVE->value)
                ->exists();

            if (! $hasActiveLink) {
                throw new CardNotLinkedException(
                    'Vui lòng liên kết tài khoản ngân hàng trước khi thiết lập hạn mức chi tiêu.',
                );
            }

            /** @var SpendingLimit|null $limit */
            $limit = SpendingLimit::query()
                ->where('student_card_id', $card->id)
                ->lockForUpdate()
                ->first();

            if ($limit === null) {
                $limit = SpendingLimit::create([
                    'student_card_id' => $card->id,
                    'daily_limit' => 0,
                    'spent_today' => 0,
                    'spent_date' => $today,
                ]);
            }

            $previousLimit = (int) $limit->daily_limit;
            $effectiveSpent = (string) $limit->spent_date === $today ? (int) $limit->spent_today : 0;

            $limit->update([
                'daily_limit' => $newDailyLimit,
                'spent_today' => $effectiveSpent,
                'spent_date' => $today,
            ]);

            if ($previousLimit !== $newDailyLimit) {
                SpendingLimitLog::create([
                    'student_card_id' => $card->id,
                    'changed_by' => $user->id,
                    'previous_limit' => $previousLimit,
                    'new_limit' => $newDailyLimit,
                    'reason' => $normalizedReason,
                    'created_at' => now(),
                ]);
            }

            return $card->load(['student', 'activeBankLink', 'spendingLimit']);
        });
    }

    public function updateDailyLimitByAdmin(
        User $admin,
        int $cardId,
        int $newDailyLimit,
        ?string $reason = null,
    ): StudentCard {
        $normalizedReason = $reason !== null && trim($reason) !== ''
            ? trim($reason)
            : 'Quản trị viên điều chỉnh hạn mức';
        $today = $this->todayDate();

        return DB::transaction(function () use ($admin, $cardId, $newDailyLimit, $normalizedReason, $today): StudentCard {
            /** @var StudentCard|null $card */
            $card = StudentCard::query()
                ->whereKey($cardId)
                ->lockForUpdate()
                ->first();

            if ($card === null) {
                throw (new ModelNotFoundException)->setModel(StudentCard::class, [$cardId]);
            }

            if ($card->status !== StudentCardStatus::ACTIVE) {
                throw ValidationException::withMessages([
                    'card' => ['Chỉ có thể cập nhật hạn mức cho thẻ đang ở trạng thái hoạt động (ACTIVE).'],
                ]);
            }

            $hasActiveLink = BankLink::query()
                ->where('student_card_id', $card->id)
                ->where('status', BankLinkStatus::ACTIVE->value)
                ->exists();

            if (! $hasActiveLink) {
                throw new CardNotLinkedException(
                    'Thẻ học sinh chưa liên kết tài khoản ngân hàng nên không thể thiết lập hạn mức.',
                );
            }

            /** @var SpendingLimit|null $limit */
            $limit = SpendingLimit::query()
                ->where('student_card_id', $card->id)
                ->lockForUpdate()
                ->first();

            if ($limit === null) {
                $limit = SpendingLimit::create([
                    'student_card_id' => $card->id,
                    'daily_limit' => 0,
                    'spent_today' => 0,
                    'spent_date' => $today,
                ]);
            }

            $previousLimit = (int) $limit->daily_limit;
            $effectiveSpent = (string) $limit->spent_date === $today ? (int) $limit->spent_today : 0;

            $limit->update([
                'daily_limit' => $newDailyLimit,
                'spent_today' => $effectiveSpent,
                'spent_date' => $today,
            ]);

            if ($previousLimit !== $newDailyLimit) {
                SpendingLimitLog::create([
                    'student_card_id' => $card->id,
                    'changed_by' => $admin->id,
                    'previous_limit' => $previousLimit,
                    'new_limit' => $newDailyLimit,
                    'reason' => $normalizedReason,
                    'created_at' => now(),
                ]);
            }

            return $card->load(['student', 'activeBankLink', 'spendingLimit']);
        });
    }

    /**
     * Lock spending_limits and sandbox_bank_accounts right after student_cards
     * (canonical order: student_cards -> spending_limits -> sandbox_bank_accounts -> products -> orders -> payment_transactions).
     *
     * @return array{limit: SpendingLimit, bank_link: BankLink}
     */
    public function lockCardLimitForPayment(StudentCard $lockedCard): array
    {
        if ($lockedCard->status !== StudentCardStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'student_card_id' => ['Thẻ học sinh hiện không ở trạng thái hoạt động.'],
            ]);
        }

        /** @var BankLink|null $activeBankLink */
        $activeBankLink = BankLink::query()
            ->where('student_card_id', $lockedCard->id)
            ->where('status', BankLinkStatus::ACTIVE->value)
            ->first();

        if ($activeBankLink === null) {
            throw new CardNotLinkedException(
                'Thẻ học sinh chưa liên kết tài khoản ngân hàng nên chưa thể thanh toán.',
            );
        }

        $today = $this->todayDate();

        /** @var SpendingLimit|null $limit */
        $limit = SpendingLimit::query()
            ->where('student_card_id', $lockedCard->id)
            ->lockForUpdate()
            ->first();

        if ($limit === null) {
            $limit = SpendingLimit::create([
                'student_card_id' => $lockedCard->id,
                'daily_limit' => 0,
                'spent_today' => 0,
                'spent_date' => $today,
            ]);
        }

        if (is_string($activeBankLink->provider_reference) && $activeBankLink->provider_reference !== '') {
            SandboxBankAccount::query()
                ->where('provider_reference', $activeBankLink->provider_reference)
                ->lockForUpdate()
                ->first();
        }

        return [
            'limit' => $limit,
            'bank_link' => $activeBankLink,
        ];
    }

    /**
     * @return array{remaining_limit_snapshot: int, payment_reference: string}
     */
    public function consumeLockedLimit(
        SpendingLimit $lockedLimit,
        BankLink $activeBankLink,
        int $amountVnd,
        string $orderCode,
    ): array {
        $today = $this->todayDate();
        $dailyLimit = (int) $lockedLimit->daily_limit;
        $effectiveSpent = (string) $lockedLimit->spent_date === $today
            ? (int) $lockedLimit->spent_today
            : 0;
        $remaining = max(0, $dailyLimit - $effectiveSpent);

        if ($amountVnd > $remaining) {
            throw new SpendingLimitExceededException(
                sprintf(
                    'Số tiền đơn hàng (%s đ) vượt quá hạn mức chi tiêu còn lại hôm nay (%s đ).',
                    number_format($amountVnd, 0, ',', '.'),
                    number_format($remaining, 0, ',', '.'),
                ),
                [
                    'daily_limit' => $dailyLimit,
                    'spent_today' => $effectiveSpent,
                    'remaining_limit' => $remaining,
                    'required_amount' => $amountVnd,
                ],
            );
        }

        $charge = $this->gateway->chargeAutoDebit(
            (string) $activeBankLink->provider_reference,
            $amountVnd,
            $orderCode,
        );

        $newSpent = $effectiveSpent + $amountVnd;
        $lockedLimit->update([
            'spent_today' => $newSpent,
            'spent_date' => $today,
        ]);

        return [
            'remaining_limit_snapshot' => max(0, $dailyLimit - $newSpent),
            'payment_reference' => $charge['charge_reference'],
            'provider' => $this->gateway->providerName(),
        ];
    }

    public function refundForCancelledOrder(Order $lockedOrder, ?SpendingLimit $lockedLimit = null): void
    {
        if ($lockedOrder->payment_status !== PaymentStatus::PAID) {
            return;
        }

        if ($lockedOrder->payment_method === PaymentMethod::VNPAY) {
            // Paid VNPay orders require manual/gateway refund reconciliation
            $lockedOrder->update([
                'payment_status' => PaymentStatus::PENDING_RECONCILE,
            ]);

            return;
        }

        if (
            ! in_array($lockedOrder->payment_method, [PaymentMethod::STUDENT_CARD, PaymentMethod::STUDENT_CARD_POS], true)
            || $lockedOrder->student_card_id === null
        ) {
            return;
        }

        if ($lockedOrder->payment_reference !== null) {
            $originalChargeTx = PaymentTransaction::query()
                ->where('order_id', $lockedOrder->id)
                ->where('type', 'CHARGE')
                ->where('provider_reference', $lockedOrder->payment_reference)
                ->first();

            $refundResult = $this->gateway->refundAutoDebit(
                (string) $lockedOrder->payment_reference,
                (int) $lockedOrder->total_amount,
                $lockedOrder->code,
            );

            $bankLinkId = $originalChargeTx?->bank_link_id
                ?? BankLink::query()
                    ->where('student_card_id', $lockedOrder->student_card_id)
                    ->latest('id')
                    ->value('id');

            $this->ledger->recordRefundSuccess(
                order: $lockedOrder,
                bankLinkId: $bankLinkId !== null ? (int) $bankLinkId : null,
                provider: $originalChargeTx?->provider ?? $this->gateway->providerName(),
                refundReference: $refundResult['refund_reference'],
            );
        }

        $lockedOrder->update([
            'payment_status' => PaymentStatus::REFUNDED,
        ]);

        $today = $this->todayDate();
        $orderDate = $lockedOrder->created_at?->copy()->timezone(self::TIMEZONE)->toDateString();

        if ($orderDate !== $today) {
            return;
        }

        /** @var SpendingLimit|null $limit */
        $limit = $lockedLimit ?? SpendingLimit::query()
            ->where('student_card_id', $lockedOrder->student_card_id)
            ->lockForUpdate()
            ->first();

        if ($limit !== null && (string) $limit->spent_date === $today) {
            $limit->update([
                'spent_today' => max(0, (int) $limit->spent_today - (int) $lockedOrder->total_amount),
            ]);
        }
    }

    public function listLogs(User $user, int $cardId, int $perPage = 10): LengthAwarePaginator
    {
        $cardExists = StudentCard::query()
            ->whereKey($cardId)
            ->whereHas('student', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true))
            ->exists();

        if (! $cardExists) {
            throw (new ModelNotFoundException)->setModel(StudentCard::class, [$cardId]);
        }

        return SpendingLimitLog::query()
            ->with('actor')
            ->where('student_card_id', $cardId)
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listCardOrders(User $user, int $cardId, int $perPage = 4): LengthAwarePaginator
    {
        $cardExists = StudentCard::query()
            ->whereKey($cardId)
            ->whereHas('student', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true))
            ->exists();

        if (! $cardExists) {
            throw (new ModelNotFoundException)->setModel(StudentCard::class, [$cardId]);
        }

        return Order::query()
            ->with(['items.product', 'studentCard.student'])
            ->where('student_card_id', $cardId)
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
