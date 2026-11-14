<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;

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
        $startOfDay = now()->startOfDay();
        $endOfDay = now()->endOfDay();

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
            'date' => now()->toDateString(),
            'today_total_orders' => $todayTotalOrders,
            'today_completed_revenue' => $todayCompletedRevenue,
            'today_active_orders' => $todayActiveOrders,
            'counts_by_status' => $countsByStatus,
        ];
    }
}
