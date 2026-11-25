<?php

namespace App\Services;

use App\Models\BankLink;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;

class PaymentLedgerService
{
    public function recordChargeSuccess(
        Order $order,
        BankLink $bankLink,
        string $provider,
        string $providerReference,
        ?string $idempotencyKey = null,
    ): PaymentTransaction {
        return PaymentTransaction::create([
            'order_id' => $order->id,
            'student_card_id' => $order->student_card_id,
            'bank_link_id' => $bankLink->id,
            'user_id' => $order->user_id,
            'provider' => $provider,
            'type' => 'CHARGE',
            'amount' => (int) $order->total_amount,
            'status' => 'SUCCEEDED',
            'idempotency_key' => $idempotencyKey,
            'provider_reference' => $providerReference,
            'gateway_txn_ref' => $order->code,
            'response_meta' => [
                'bank_code' => $bankLink->bank_code,
                'account_number_masked' => $bankLink->account_number_masked,
            ],
            'processed_at' => now(),
        ]);
    }

    public function recordRefundSuccess(
        Order $order,
        ?int $bankLinkId,
        string $provider,
        string $refundReference,
    ): PaymentTransaction {
        return PaymentTransaction::create([
            'order_id' => $order->id,
            'student_card_id' => $order->student_card_id,
            'bank_link_id' => $bankLinkId,
            'user_id' => $order->user_id,
            'provider' => $provider,
            'type' => 'REFUND',
            'amount' => (int) $order->total_amount,
            'status' => 'SUCCEEDED',
            'provider_reference' => $refundReference,
            'gateway_txn_ref' => 'REF-'.$order->code.'-'.$order->id,
            'response_meta' => [
                'original_charge_reference' => $order->payment_reference,
            ],
            'processed_at' => now(),
        ]);
    }

    public function recordFailedAttempt(
        User $user,
        ?int $studentCardId,
        ?int $bankLinkId,
        string $provider,
        int $amount,
        string $status,
        string $failureCode,
        string $failureMessage,
        ?string $idempotencyKey = null,
    ): PaymentTransaction {
        return PaymentTransaction::create([
            'order_id' => null,
            'student_card_id' => $studentCardId,
            'bank_link_id' => $bankLinkId,
            'user_id' => $user->id,
            'provider' => $provider,
            'type' => 'CHARGE',
            'amount' => max(0, $amount),
            'status' => $status,
            'failure_code' => $failureCode,
            'failure_message' => mb_substr($failureMessage, 0, 255),
            'idempotency_key' => $idempotencyKey,
            'provider_reference' => null,
            'gateway_txn_ref' => null,
            'processed_at' => now(),
        ]);
    }
}
