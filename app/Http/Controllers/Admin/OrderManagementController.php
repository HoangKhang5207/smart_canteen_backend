<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Exceptions\OrderVersionConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderManagementController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    public function index(Request $request): View
    {
        $statusFilter = trim((string) $request->query('status', ''));
        $search = trim((string) $request->query('search', ''));

        $query = Order::query()
            ->with(['user', 'items.product'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($statusFilter !== '') {
            $parsed = OrderStatus::tryFrom(strtoupper($statusFilter));
            if ($parsed !== null) {
                $query->where('status', $parsed->value);
            }
        }

        if ($search !== '') {
            $query->whereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }

        return view('admin.orders.index', [
            'orders' => $query->limit(50)->get(),
            'statusFilter' => strtoupper($statusFilter),
            'search' => $search,
        ]);
    }

    public function show(Order $order): View
    {
        $order->load(['user', 'items.product', 'statusLogs.actor']);

        return view('admin.orders.show', [
            'order' => $order,
        ]);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): RedirectResponse
    {
        $validated = $request->validated();
        $nextStatus = OrderStatus::from((string) $validated['status']);
        $expectedVersion = (int) $validated['version'];

        try {
            $updated = $this->orderService->transitionStatus(
                $order,
                $nextStatus,
                $expectedVersion,
                $request->user(),
            );
        } catch (OrderVersionConflictException|InvalidOrderStatusTransitionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'status',
            sprintf('Đã chuyển đơn %s sang trạng thái %s.', $updated->code, $updated->status->value),
        );
    }
}
