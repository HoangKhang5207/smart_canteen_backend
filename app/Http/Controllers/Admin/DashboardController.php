<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\StatisticsService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly StatisticsService $statisticsService,
    ) {}

    public function index(): View
    {
        $summary = $this->statisticsService->getTodaySummary();

        $activeOrders = Order::query()
            ->with(['user', 'items.product'])
            ->whereIn('status', [
                OrderStatus::PENDING->value,
                OrderStatus::PREPARING->value,
                OrderStatus::READY->value,
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        return view('admin.dashboard', [
            'summary' => $summary,
            'activeOrders' => $activeOrders,
        ]);
    }
}
