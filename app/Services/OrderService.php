<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientBankBalanceException;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Exceptions\OrderVersionConflictException;
use App\Exceptions\PaymentGatewayException;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\SandboxBankAccount;
use App\Models\SpendingLimit;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly SpendingLimitService $spendingLimitService,
        private readonly PaymentLedgerService $ledger,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     * @return array{order: Order, created: bool}
     */
    public function createOrder(
        User $user,
        array $items,
        ?string $note,
        string $idempotencyKey,
        ?int $studentCardId = null,
        ?PaymentMethod $paymentMethod = null,
    ): array {
        $normalizedNote = $note !== null && trim($note) !== '' ? trim($note) : null;
        $resolvedPaymentMethod = $paymentMethod
            ?? ($studentCardId !== null ? PaymentMethod::STUDENT_CARD : PaymentMethod::COUNTER_CASH);

        if ($resolvedPaymentMethod === PaymentMethod::STUDENT_CARD && $studentCardId === null) {
            throw ValidationException::withMessages([
                'student_card_id' => ['Vui lòng chọn thẻ học sinh khi thanh toán bằng thẻ học sinh.'],
            ]);
        }

        $effectiveCardId = $resolvedPaymentMethod === PaymentMethod::STUDENT_CARD ? $studentCardId : null;
        $payloadHash = $this->computePayloadHash($items, $normalizedNote, $effectiveCardId, $resolvedPaymentMethod);

        $existing = Order::query()
            ->with(['items.product', 'studentCard.student'])
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ($existing->payload_hash !== $payloadHash) {
                throw new IdempotencyConflictException(
                    'Idempotency-Key has already been used with a different order payload.',
                );
            }

            return ['order' => $existing, 'created' => false];
        }

        $productIds = array_map(fn (array $row) => (int) $row['product_id'], $items);
        $failedContext = ['card_id' => $effectiveCardId, 'bank_link_id' => null, 'amount' => 0];

        try {
            $order = DB::transaction(function () use (
                $user,
                $items,
                $productIds,
                $normalizedNote,
                $idempotencyKey,
                $payloadHash,
                $effectiveCardId,
                $resolvedPaymentMethod,
                &$failedContext,
            ): Order {
                // Canonical lock order: student_cards -> spending_limits -> sandbox_bank_accounts -> products -> orders
                $lockedCard = null;
                $lockedLimitBundle = null;

                if ($effectiveCardId !== null) {
                    $lockedCard = StudentCard::query()
                        ->with('student')
                        ->whereKey($effectiveCardId)
                        ->whereHas('student', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true))
                        ->lockForUpdate()
                        ->first();

                    if ($lockedCard === null) {
                        throw (new ModelNotFoundException)->setModel(StudentCard::class, [$effectiveCardId]);
                    }

                    $lockedLimitBundle = $this->spendingLimitService->lockCardLimitForPayment($lockedCard);
                    $failedContext['bank_link_id'] = $lockedLimitBundle['bank_link']->id;
                }

                [$totalAmount, $orderItemsPayload] = $this->resolveAndLockProducts($items, $productIds);
                $failedContext['amount'] = $totalAmount;

                $tempCode = 'TMP-'.Str::random(16);
                $paymentStatus = PaymentStatus::UNPAID;
                $remainingSnapshot = null;
                $paymentReference = null;
                $paymentResult = null;
                $storeName = $lockedCard !== null ? $lockedCard->student->school_name : 'Căn tin Smart Canteen';

                $order = Order::create([
                    'user_id' => $user->id,
                    'student_card_id' => $lockedCard?->id,
                    'code' => $tempCode,
                    'status' => OrderStatus::PENDING,
                    'payment_method' => $resolvedPaymentMethod,
                    'payment_status' => $paymentStatus,
                    'store_name' => $storeName,
                    'total_amount' => $totalAmount,
                    'discount_amount' => 0,
                    'remaining_limit_snapshot' => $remainingSnapshot,
                    'payment_reference' => $paymentReference,
                    'note' => $normalizedNote,
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'version' => 1,
                ]);

                $finalCode = sprintf('SC-%s-%06d', now(SpendingLimitService::TIMEZONE)->format('ymd'), $order->id);

                if ($lockedLimitBundle !== null) {
                    $paymentResult = $this->spendingLimitService->consumeLockedLimit(
                        $lockedLimitBundle['limit'],
                        $lockedLimitBundle['bank_link'],
                        $totalAmount,
                        $finalCode,
                    );
                    $paymentStatus = PaymentStatus::PAID;
                    $remainingSnapshot = $paymentResult['remaining_limit_snapshot'];
                    $paymentReference = $paymentResult['payment_reference'];
                }

                $order->update([
                    'code' => $finalCode,
                    'payment_status' => $paymentStatus,
                    'remaining_limit_snapshot' => $remainingSnapshot,
                    'payment_reference' => $paymentReference,
                ]);

                foreach ($orderItemsPayload as $itemData) {
                    $order->items()->create($itemData);
                }

                if ($lockedLimitBundle !== null && $paymentResult !== null) {
                    $this->ledger->recordChargeSuccess(
                        order: $order,
                        bankLink: $lockedLimitBundle['bank_link'],
                        provider: $paymentResult['provider'],
                        providerReference: $paymentResult['payment_reference'],
                        idempotencyKey: $idempotencyKey,
                    );
                }

                return $order->load(['items.product', 'studentCard.student']);
            });

            return ['order' => $order, 'created' => true];
        } catch (InsufficientBankBalanceException $e) {
            $this->ledger->recordFailedAttempt(
                user: $user,
                studentCardId: $failedContext['card_id'],
                bankLinkId: $failedContext['bank_link_id'],
                provider: $this->spendingLimitService->gatewayProviderName(),
                amount: $failedContext['amount'],
                status: 'FAILED',
                failureCode: $e->errorCode,
                failureMessage: $e->getMessage(),
                idempotencyKey: $idempotencyKey,
            );
            throw $e;
        } catch (PaymentGatewayException $e) {
            $this->ledger->recordFailedAttempt(
                user: $user,
                studentCardId: $failedContext['card_id'],
                bankLinkId: $failedContext['bank_link_id'],
                provider: $this->spendingLimitService->gatewayProviderName(),
                amount: $failedContext['amount'],
                status: $e->transactionStatus,
                failureCode: $e->errorCode,
                failureMessage: $e->getMessage(),
                idempotencyKey: $idempotencyKey,
            );
            throw $e;
        } catch (UniqueConstraintViolationException $e) {
            return $this->resolveIdempotentConflict($user, $idempotencyKey, $payloadHash, $e);
        }
    }

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     * @return array{order: Order, created: bool}
     */
    public function createPosPurchase(
        User $user,
        int $cardId,
        array $items,
        ?string $storeName,
        string $idempotencyKey,
    ): array {
        $normalizedStore = $storeName !== null && trim($storeName) !== '' ? trim($storeName) : null;
        $payloadHash = $this->computePayloadHash(
            $items,
            $normalizedStore,
            $cardId,
            PaymentMethod::STUDENT_CARD_POS,
        );

        $existing = Order::query()
            ->with(['items.product', 'studentCard.student'])
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ($existing->payload_hash !== $payloadHash) {
                throw new IdempotencyConflictException(
                    'Idempotency-Key has already been used with a different POS purchase payload.',
                );
            }

            return ['order' => $existing, 'created' => false];
        }

        $productIds = array_map(fn (array $row) => (int) $row['product_id'], $items);
        $failedContext = ['card_id' => $cardId, 'bank_link_id' => null, 'amount' => 0];

        try {
            $order = DB::transaction(function () use (
                $user,
                $cardId,
                $items,
                $productIds,
                $normalizedStore,
                $idempotencyKey,
                $payloadHash,
                &$failedContext,
            ): Order {
                // Canonical lock order: student_cards -> spending_limits -> sandbox_bank_accounts -> products -> orders
                /** @var StudentCard|null $lockedCard */
                $lockedCard = StudentCard::query()
                    ->with('student')
                    ->whereKey($cardId)
                    ->whereHas('student', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true))
                    ->lockForUpdate()
                    ->first();

                if ($lockedCard === null) {
                    throw (new ModelNotFoundException)->setModel(StudentCard::class, [$cardId]);
                }

                $lockedLimitBundle = $this->spendingLimitService->lockCardLimitForPayment($lockedCard);
                $failedContext['bank_link_id'] = $lockedLimitBundle['bank_link']->id;

                [$totalAmount, $orderItemsPayload] = $this->resolveAndLockProducts($items, $productIds);
                $failedContext['amount'] = $totalAmount;

                $order = Order::create([
                    'user_id' => $user->id,
                    'student_card_id' => $lockedCard->id,
                    'code' => 'TMP-'.Str::random(16),
                    'status' => OrderStatus::COMPLETED,
                    'payment_method' => PaymentMethod::STUDENT_CARD_POS,
                    'payment_status' => PaymentStatus::PAID,
                    'store_name' => $normalizedStore ?? $lockedCard->student->school_name,
                    'total_amount' => $totalAmount,
                    'discount_amount' => 0,
                    'remaining_limit_snapshot' => null,
                    'payment_reference' => null,
                    'note' => 'Mua hàng trực tiếp tại quầy POS bằng thẻ học sinh',
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'version' => 1,
                ]);

                $finalCode = sprintf('POS-%s-%06d', now(SpendingLimitService::TIMEZONE)->format('ymd'), $order->id);

                $paymentResult = $this->spendingLimitService->consumeLockedLimit(
                    $lockedLimitBundle['limit'],
                    $lockedLimitBundle['bank_link'],
                    $totalAmount,
                    $finalCode,
                );

                $order->update([
                    'code' => $finalCode,
                    'remaining_limit_snapshot' => $paymentResult['remaining_limit_snapshot'],
                    'payment_reference' => $paymentResult['payment_reference'],
                ]);

                foreach ($orderItemsPayload as $itemData) {
                    $order->items()->create($itemData);
                }

                $this->ledger->recordChargeSuccess(
                    order: $order,
                    bankLink: $lockedLimitBundle['bank_link'],
                    provider: $paymentResult['provider'],
                    providerReference: $paymentResult['payment_reference'],
                    idempotencyKey: $idempotencyKey,
                );

                return $order->load(['items.product', 'studentCard.student']);
            });

            return ['order' => $order, 'created' => true];
        } catch (InsufficientBankBalanceException $e) {
            $this->ledger->recordFailedAttempt(
                user: $user,
                studentCardId: $failedContext['card_id'],
                bankLinkId: $failedContext['bank_link_id'],
                provider: $this->spendingLimitService->gatewayProviderName(),
                amount: $failedContext['amount'],
                status: 'FAILED',
                failureCode: $e->errorCode,
                failureMessage: $e->getMessage(),
                idempotencyKey: $idempotencyKey,
            );
            throw $e;
        } catch (PaymentGatewayException $e) {
            $this->ledger->recordFailedAttempt(
                user: $user,
                studentCardId: $failedContext['card_id'],
                bankLinkId: $failedContext['bank_link_id'],
                provider: $this->spendingLimitService->gatewayProviderName(),
                amount: $failedContext['amount'],
                status: $e->transactionStatus,
                failureCode: $e->errorCode,
                failureMessage: $e->getMessage(),
                idempotencyKey: $idempotencyKey,
            );
            throw $e;
        } catch (UniqueConstraintViolationException $e) {
            return $this->resolveIdempotentConflict($user, $idempotencyKey, $payloadHash, $e);
        }
    }

    public function transitionStatus(
        Order $order,
        OrderStatus $nextStatus,
        int $expectedVersion,
        User $actor,
    ): Order {
        $studentCardId = $nextStatus === OrderStatus::CANCELLED
            ? Order::query()->whereKey($order->id)->value('student_card_id')
            : null;
        $sandboxBankProviderRef = null;
        if ($nextStatus === OrderStatus::CANCELLED && $studentCardId !== null) {
            $chargeTx = PaymentTransaction::query()
                ->with('bankLink:id,provider_reference')
                ->where('order_id', $order->id)
                ->where('type', 'CHARGE')
                ->latest('id')
                ->first();
            $sandboxBankProviderRef = $chargeTx?->bankLink?->provider_reference;
        }

        return DB::transaction(function () use (
            $order,
            $nextStatus,
            $expectedVersion,
            $actor,
            $studentCardId,
            $sandboxBankProviderRef,
        ): Order {
            // Preserve canonical lock order: student_cards -> spending_limits -> sandbox_bank_accounts -> orders -> payment_transactions
            $lockedLimit = null;
            if ($studentCardId !== null) {
                StudentCard::query()->whereKey($studentCardId)->lockForUpdate()->first();
                $lockedLimit = SpendingLimit::query()
                    ->where('student_card_id', $studentCardId)
                    ->lockForUpdate()
                    ->first();

                if (is_string($sandboxBankProviderRef) && $sandboxBankProviderRef !== '') {
                    SandboxBankAccount::query()
                        ->where('provider_reference', $sandboxBankProviderRef)
                        ->lockForUpdate()
                        ->first();
                }
            }

            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedOrder->version !== $expectedVersion) {
                throw new OrderVersionConflictException(
                    'Đơn hàng đã được nhân viên khác cập nhật trước đó. Vui lòng tải lại trang.',
                );
            }

            $currentStatus = $lockedOrder->status instanceof OrderStatus
                ? $lockedOrder->status
                : OrderStatus::from((string) $lockedOrder->status);

            if (! $currentStatus->canTransitionTo($nextStatus)) {
                throw new InvalidOrderStatusTransitionException(
                    sprintf('Không thể chuyển trạng thái đơn hàng từ %s sang %s.', $currentStatus->value, $nextStatus->value),
                );
            }

            $lockedOrder->update([
                'status' => $nextStatus,
                'version' => (int) $lockedOrder->version + 1,
            ]);

            if ($nextStatus === OrderStatus::CANCELLED) {
                $this->spendingLimitService->refundForCancelledOrder($lockedOrder, $lockedLimit);
            }

            OrderStatusLog::create([
                'order_id' => $lockedOrder->id,
                'from_status' => $currentStatus,
                'to_status' => $nextStatus,
                'changed_by' => $actor->id,
            ]);

            return $lockedOrder->load(['user', 'items.product', 'statusLogs.actor', 'studentCard.student']);
        });
    }

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     * @param  list<int>  $productIds
     * @return array{0: int, 1: list<array{product_id: int, quantity: int, unit_price: int, subtotal: int}>}
     */
    private function resolveAndLockProducts(array $items, array $productIds): array
    {
        $products = Product::query()
            ->whereHas('category', fn ($q) => $q->active())
            ->where('is_available', true)
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($products->count() !== count($productIds)) {
            throw ValidationException::withMessages([
                'items' => ['Một hoặc nhiều món ăn không tồn tại hoặc đã ngừng phục vụ.'],
            ]);
        }

        $totalAmount = 0;
        $orderItemsPayload = [];

        foreach ($items as $row) {
            /** @var Product $product */
            $product = $products->get((int) $row['product_id']);
            $quantity = (int) $row['quantity'];
            $unitPrice = (int) $product->price;
            $subtotal = $unitPrice * $quantity;
            $totalAmount += $subtotal;

            $orderItemsPayload[] = [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
            ];
        }

        return [$totalAmount, $orderItemsPayload];
    }

    /**
     * @return array{order: Order, created: bool}
     */
    private function resolveIdempotentConflict(
        User $user,
        string $idempotencyKey,
        string $payloadHash,
        UniqueConstraintViolationException $originalException,
    ): array {
        $concurrent = Order::query()
            ->with(['items.product', 'studentCard.student'])
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($concurrent === null) {
            throw $originalException;
        }

        if ($concurrent->payload_hash !== $payloadHash) {
            throw new IdempotencyConflictException(
                'Idempotency-Key has already been used with a different order payload.',
            );
        }

        return ['order' => $concurrent, 'created' => false];
    }

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     */
    private function computePayloadHash(
        array $items,
        ?string $note,
        ?int $studentCardId = null,
        PaymentMethod $paymentMethod = PaymentMethod::COUNTER_CASH,
    ): string {
        $normalized = array_map(fn (array $row) => [
            'product_id' => (int) $row['product_id'],
            'quantity' => (int) $row['quantity'],
        ], $items);

        usort($normalized, fn (array $a, array $b) => $a['product_id'] <=> $b['product_id']);

        $data = [
            'items' => $normalized,
            'note' => $note,
        ];

        if ($studentCardId !== null || $paymentMethod !== PaymentMethod::COUNTER_CASH) {
            $data['student_card_id'] = $studentCardId;
            $data['payment_method'] = $paymentMethod->value;
        }

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
