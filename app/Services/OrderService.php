<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Exceptions\OrderVersionConflictException;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     * @return array{order: Order, created: bool}
     */
    public function createOrder(
        User $user,
        array $items,
        ?string $note,
        string $idempotencyKey,
    ): array {
        $normalizedNote = $note !== null && trim($note) !== '' ? trim($note) : null;
        $payloadHash = $this->computePayloadHash($items, $normalizedNote);

        $existing = Order::query()
            ->with('items.product')
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

        try {
            $order = DB::transaction(function () use ($user, $items, $productIds, $normalizedNote, $idempotencyKey, $payloadHash): Order {
                $products = Product::query()
                    ->whereHas('category', fn ($q) => $q->active())
                    ->where('is_available', true)
                    ->whereIn('id', $productIds)
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

                $order = Order::create([
                    'user_id' => $user->id,
                    'code' => 'TMP-'.Str::random(16),
                    'status' => OrderStatus::PENDING,
                    'total_amount' => $totalAmount,
                    'note' => $normalizedNote,
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'version' => 1,
                ]);

                $finalCode = sprintf('SC-%s-%06d', now()->format('ymd'), $order->id);
                $order->update(['code' => $finalCode]);

                foreach ($orderItemsPayload as $itemData) {
                    $order->items()->create($itemData);
                }

                return $order->load('items.product');
            });

            return ['order' => $order, 'created' => true];
        } catch (UniqueConstraintViolationException) {
            $concurrent = Order::query()
                ->with('items.product')
                ->where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            if ($concurrent->payload_hash !== $payloadHash) {
                throw new IdempotencyConflictException(
                    'Idempotency-Key has already been used with a different order payload.',
                );
            }

            return ['order' => $concurrent, 'created' => false];
        }
    }

    public function transitionStatus(
        Order $order,
        OrderStatus $nextStatus,
        int $expectedVersion,
        User $actor,
    ): Order {
        return DB::transaction(function () use ($order, $nextStatus, $expectedVersion, $actor): Order {
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

            OrderStatusLog::create([
                'order_id' => $lockedOrder->id,
                'from_status' => $currentStatus,
                'to_status' => $nextStatus,
                'changed_by' => $actor->id,
            ]);

            return $lockedOrder->load(['user', 'items.product', 'statusLogs.actor']);
        });
    }

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     */
    private function computePayloadHash(array $items, ?string $note): string
    {
        $normalized = array_map(fn (array $row) => [
            'product_id' => (int) $row['product_id'],
            'quantity' => (int) $row['quantity'],
        ], $items);

        usort($normalized, fn (array $a, array $b) => $a['product_id'] <=> $b['product_id']);

        return hash('sha256', json_encode([
            'items' => $normalized,
            'note' => $note,
        ], JSON_THROW_ON_ERROR));
    }
}
