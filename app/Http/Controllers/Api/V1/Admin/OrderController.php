<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Exceptions\OrderVersionConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->filled('status') && is_string($request->input('status'))) {
            $request->merge([
                'status' => strtoupper(trim($request->input('status'))),
            ]);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::enum(OrderStatus::class)],
            'search' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? $validated['limit'] ?? 20);

        $query = Order::query()
            ->with(['user', 'items.product', 'statusLogs.actor'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $search = mb_strtolower(trim((string) $validated['search']));
            $query->whereRaw('LOWER(code) LIKE ?', ['%'.$search.'%']);
        }

        return OrderResource::collection(
            $query->paginate($perPage)->withQueryString()
        )->response();
    }

    public function updateStatus(UpdateOrderStatusRequest $request, int $id): JsonResponse
    {
        $order = Order::query()->findOrFail($id);
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
        } catch (OrderVersionConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        } catch (InvalidOrderStatusTransitionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [
                    'status' => [$e->getMessage()],
                ],
            ], 422);
        }

        return (new OrderResource($updated))->response();
    }
}
