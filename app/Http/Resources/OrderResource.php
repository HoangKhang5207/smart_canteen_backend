<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof OrderStatus ? $this->status->value : (string) $this->status;
        $paymentMethod = $this->payment_method instanceof PaymentMethod
            ? $this->payment_method
            : PaymentMethod::tryFrom((string) ($this->payment_method ?? '')) ?? PaymentMethod::COUNTER_CASH;
        $paymentStatus = $this->payment_status instanceof PaymentStatus
            ? $this->payment_status
            : PaymentStatus::tryFrom((string) ($this->payment_status ?? '')) ?? PaymentStatus::UNPAID;

        $discountAmount = (int) ($this->discount_amount ?? 0);
        $totalAmount = (int) $this->total_amount;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'student_card_id' => $this->student_card_id !== null ? (int) $this->student_card_id : null,
            'card_code' => $this->whenLoaded('studentCard', fn () => $this->studentCard?->card_code),
            'student_name' => $this->whenLoaded('studentCard', fn () => $this->studentCard?->student?->full_name),
            'student_class' => $this->whenLoaded('studentCard', fn () => $this->studentCard?->student?->class_name),
            'customer_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'code' => $this->code,
            'status' => $status,
            'payment_method' => $paymentMethod->value,
            'payment_method_label' => $paymentMethod->label(),
            'payment_status' => $paymentStatus->value,
            'payment_status_label' => $paymentStatus->label(),
            'store_name' => $this->store_name ?? 'Căn tin Smart Canteen',
            'subtotal_amount' => $totalAmount + $discountAmount,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
            'remaining_limit_snapshot' => $this->remaining_limit_snapshot !== null
                ? (int) $this->remaining_limit_snapshot
                : null,
            'note' => $this->note,
            'version' => (int) $this->version,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'status_logs' => OrderStatusLogResource::collection($this->whenLoaded('statusLogs')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
