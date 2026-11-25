<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethod;
use App\Exceptions\CardNotLinkedException;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientBankBalanceException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PaymentGatewayException;
use App\Exceptions\SpendingLimitExceededException;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StudentCard;
use App\Services\OrderService;
use App\Services\SpendingLimitService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PosTerminalController extends Controller
{
    public function __construct(
        private readonly SpendingLimitService $spendingLimitService,
    ) {}

    public function index(): View
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $products = Product::query()
            ->with('category:id,name')
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->where('is_available', true)
            ->orderBy('category_id')
            ->orderBy('id')
            ->get();

        $todayStartUtc = now(SpendingLimitService::TIMEZONE)->startOfDay()->utc();
        $recentPosOrders = Order::query()
            ->with(['studentCard.student', 'items'])
            ->where('code', 'LIKE', 'POS-%')
            ->where('created_at', '>=', $todayStartUtc)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('admin.pos.index', [
            'categories' => $categories,
            'products' => $products,
            'recentPosOrders' => $recentPosOrders,
        ]);
    }

    public function lookupCard(Request $request): JsonResponse
    {
        $query = strtoupper(trim((string) $request->query('q', '')));
        if ($query === '') {
            return response()->json([
                'message' => 'Vui lòng nhập mã thẻ hoặc mã học sinh.',
                'error_code' => 'CARD_QUERY_REQUIRED',
            ], 422);
        }

        /** @var StudentCard|null $card */
        $card = StudentCard::query()
            ->with(['student', 'activeBankLink', 'spendingLimit'])
            ->whereHas('student', fn ($q) => $q->where('is_active', true))
            ->where(function ($sub) use ($query): void {
                $sub->whereRaw('UPPER(card_code) = ?', [$query])
                    ->orWhereHas('student', fn ($sq) => $sq->whereRaw('UPPER(student_code) = ?', [$query]));
            })
            ->orderByRaw("CASE WHEN status = 'ACTIVE' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->first();

        if ($card === null || $card->student === null) {
            return response()->json([
                'message' => sprintf('Không tìm thấy thẻ học sinh hoặc mã học sinh "%s".', $query),
                'error_code' => 'CARD_NOT_FOUND',
            ], 404);
        }

        $limitSummary = $this->spendingLimitService->summarize($card->spendingLimit);
        $activeLink = $card->activeBankLink;

        return response()->json([
            'data' => [
                'id' => $card->id,
                'card_code' => $card->card_code,
                'status' => $card->status->value,
                'is_bank_linked' => $activeLink !== null,
                'bank_summary' => $activeLink !== null ? [
                    'bank_name' => $activeLink->bank_name,
                    'account_number_masked' => $activeLink->account_number_masked,
                ] : null,
                'student' => [
                    'id' => $card->student->id,
                    'student_code' => $card->student->student_code,
                    'full_name' => $card->student->full_name,
                    'school_name' => $card->student->school_name,
                    'class_name' => $card->student->class_name,
                ],
                'spending_limit' => $limitSummary,
            ],
        ]);
    }

    public function checkout(Request $request, OrderService $orderService): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => [
                'required',
                'string',
                Rule::in([PaymentMethod::STUDENT_CARD_POS->value, PaymentMethod::COUNTER_CASH->value]),
            ],
            'student_card_id' => ['nullable', 'integer', 'min:1'],
            'store_name' => ['nullable', 'string', 'max:150'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $paymentMethod = PaymentMethod::from((string) $validated['payment_method']);
        $cardId = isset($validated['student_card_id']) ? (int) $validated['student_card_id'] : null;

        try {
            $result = $orderService->createStaffPosOrder(
                staff: $request->user(),
                items: $validated['items'],
                paymentMethod: $paymentMethod,
                cardId: $cardId,
                storeName: $validated['store_name'] ?? null,
                idempotencyKey: trim((string) $validated['idempotency_key']),
            );
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Không tìm thấy thẻ học sinh đang hoạt động.',
                'error_code' => 'CARD_NOT_FOUND',
            ], 404);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
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
        } catch (InsufficientStockException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
                'meta' => $e->meta,
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

        /** @var Order $order */
        $order = $result['order'];
        $updatedProductIds = $order->items->pluck('product_id')->unique()->values()->all();
        $stockSnapshot = Product::query()
            ->whereIn('id', $updatedProductIds)
            ->get(['id', 'stock_quantity'])
            ->mapWithKeys(fn (Product $p) => [$p->id => $p->stock_quantity])
            ->all();

        return response()->json([
            'data' => [
                'order_id' => $order->id,
                'code' => $order->code,
                'total_amount' => (int) $order->total_amount,
                'payment_method' => $order->payment_method->value,
                'payment_status' => $order->payment_status->value,
                'remaining_limit_snapshot' => $order->remaining_limit_snapshot,
                'student_name' => $order->studentCard?->student?->full_name,
                'card_code' => $order->studentCard?->card_code,
                'receipt_url' => route('admin.pos.receipt', $order),
                'created' => $result['created'],
                'stock_snapshot' => $stockSnapshot,
            ],
        ], $result['created'] ? 201 : 200);
    }

    public function receipt(Order $order): View
    {
        $order->load(['items.product', 'studentCard.student', 'user', 'statusLogs.actor']);

        return view('admin.pos.receipt', [
            'order' => $order,
        ]);
    }
}
