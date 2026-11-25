<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CardNotLinkedException;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientBankBalanceException;
use App\Exceptions\PaymentGatewayException;
use App\Exceptions\SpendingLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePosPurchaseRequest;
use App\Http\Resources\OrderResource;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class CanteenPosPurchaseController extends Controller
{
    public function store(
        StorePosPurchaseRequest $request,
        int $cardId,
        OrderService $orderService,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $result = $orderService->createPosPurchase(
                user: $request->user(),
                cardId: $cardId,
                items: $validated['items'],
                storeName: $validated['store_name'] ?? null,
                idempotencyKey: $validated['idempotency_key'],
            );
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'IDEMPOTENCY_CONFLICT',
            ], 409);
        } catch (CardNotLinkedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], 422);
        } catch (SpendingLimitExceededException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
                'meta' => $e->meta,
            ], 422);
        } catch (InsufficientBankBalanceException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
                'meta' => $e->meta,
            ], 422);
        } catch (PaymentGatewayException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], $e->statusCode);
        }

        return (new OrderResource($result['order']))
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }
}
