<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\IdempotencyConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : 10;

        $orders = Order::query()
            ->with('items.product')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function store(StoreOrderRequest $request, OrderService $orderService): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $orderService->createOrder(
                $request->user(),
                $validated['items'],
                $validated['note'] ?? null,
                $validated['idempotency_key'],
            );
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }

        return (new OrderResource($result['order']))
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }

    public function show(Request $request, int $id): OrderResource
    {
        $order = Order::query()
            ->with('items.product')
            ->findOrFail($id);

        if (! Gate::forUser($request->user())->allows('view', $order)) {
            abort(404);
        }

        return new OrderResource($order);
    }
}
