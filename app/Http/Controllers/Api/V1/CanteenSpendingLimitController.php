<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CardNotLinkedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSpendingLimitRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\SpendingLimitLogResource;
use App\Http\Resources\StudentCardResource;
use App\Services\SpendingLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CanteenSpendingLimitController extends Controller
{
    public function update(
        UpdateSpendingLimitRequest $request,
        int $cardId,
        SpendingLimitService $limitService,
    ): JsonResponse|StudentCardResource {
        try {
            $card = $limitService->updateDailyLimit(
                user: $request->user(),
                cardId: $cardId,
                newDailyLimit: (int) $request->validated('daily_limit'),
                reason: $request->validated('reason'),
            );

            return new StudentCardResource($card);
        } catch (CardNotLinkedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], 422);
        }
    }

    public function logs(
        Request $request,
        int $cardId,
        SpendingLimitService $limitService,
    ): AnonymousResourceCollection {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : 10;

        return SpendingLimitLogResource::collection(
            $limitService->listLogs($request->user(), $cardId, $perPage),
        );
    }

    public function orders(
        Request $request,
        int $cardId,
        SpendingLimitService $limitService,
    ): AnonymousResourceCollection {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : 4;

        return OrderResource::collection(
            $limitService->listCardOrders($request->user(), $cardId, $perPage),
        );
    }
}
