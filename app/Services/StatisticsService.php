<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StudentCardStatus;
use App\Models\Order;
use App\Models\StudentCard;

class StatisticsService
{
    /**
     * @return array{
     *     date: string,
     *     today_total_orders: int,
     *     today_completed_revenue: int,
     *     today_active_orders: int,
     *     counts_by_status: array<string, int>
     * }
     */
    public function getTodaySummary(): array
    {
        $nowVn = now(SpendingLimitService::TIMEZONE);
        $startOfDay = $nowVn->copy()->startOfDay()->utc();
        $endOfDay = $nowVn->copy()->endOfDay()->utc();

        $rawCounts = Order::query()
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->selectRaw('status, COUNT(*) as aggregate_count')
            ->groupBy('status')
            ->pluck('aggregate_count', 'status');

        $countsByStatus = [];
        foreach (OrderStatus::cases() as $status) {
            $countsByStatus[$status->value] = (int) ($rawCounts[$status->value] ?? 0);
        }

        $todayTotalOrders = array_sum($countsByStatus);
        $todayActiveOrders = $countsByStatus[OrderStatus::PENDING->value]
            + $countsByStatus[OrderStatus::PREPARING->value]
            + $countsByStatus[OrderStatus::READY->value];

        $todayCompletedRevenue = (int) Order::query()
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('status', OrderStatus::COMPLETED->value)
            ->sum('total_amount');

        return [
            'date' => $nowVn->toDateString(),
            'today_total_orders' => $todayTotalOrders,
            'today_completed_revenue' => $todayCompletedRevenue,
            'today_active_orders' => $todayActiveOrders,
            'counts_by_status' => $countsByStatus,
        ];
    }

    /**
     * @return array{
     *     active_cards_count: int,
     *     linked_cards_count: int,
     *     today_card_orders_count: int,
     *     today_card_spent_amount: int
     * }
     */
    public function getDigitalCanteenSummary(): array
    {
        $startOfDay = now(SpendingLimitService::TIMEZONE)->startOfDay()->utc();
        $endOfDay = now(SpendingLimitService::TIMEZONE)->endOfDay()->utc();

        $activeCardsCount = StudentCard::query()
            ->where('status', StudentCardStatus::ACTIVE->value)
            ->count();

        $linkedCardsCount = StudentCard::query()
            ->whereHas('activeBankLink')
            ->count();

        $cardOrdersBase = Order::query()
            ->whereIn('payment_method', [
                PaymentMethod::STUDENT_CARD->value,
                PaymentMethod::STUDENT_CARD_POS->value,
            ])
            ->whereBetween('created_at', [$startOfDay, $endOfDay]);

        $todayCardOrdersCount = (clone $cardOrdersBase)->count();
        $todayCardSpentAmount = (int) (clone $cardOrdersBase)
            ->where('payment_status', PaymentStatus::PAID->value)
            ->sum('total_amount');

        return [
            'active_cards_count' => $activeCardsCount,
            'linked_cards_count' => $linkedCardsCount,
            'today_card_orders_count' => $todayCardOrdersCount,
            'today_card_spent_amount' => $todayCardSpentAmount,
        ];
    }
}
