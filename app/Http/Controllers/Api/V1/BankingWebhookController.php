<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\GatewayWebhookEvent;
use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankingWebhookController extends Controller
{
    private const LOCAL_DEMO_SECRET = 'local-sandbox-webhook-secret-change-me';

    public function handleSandbox(Request $request): JsonResponse
    {
        $secret = trim((string) config('services.banking.webhook_secret', ''));
        if ($secret === '' || (app()->isProduction() && $secret === self::LOCAL_DEMO_SECRET)) {
            return response()->json([
                'message' => 'Webhook secret chưa được cấu hình.',
                'error_code' => 'WEBHOOK_CONFIG_MISSING',
            ], 503);
        }

        $rawBody = (string) $request->getContent();
        $timestampHeader = trim((string) $request->header('X-Gateway-Timestamp', ''));
        $signatureHeader = trim((string) $request->header('X-Gateway-Signature', ''));

        if ($timestampHeader === '' || ! ctype_digit($timestampHeader) || $signatureHeader === '') {
            return response()->json([
                'message' => 'Thiếu header xác thực chữ ký webhook.',
                'error_code' => 'WEBHOOK_SIGNATURE_MISSING',
            ], 401);
        }

        $tolerance = max(30, (int) config('services.banking.webhook_tolerance_seconds', 300));
        $timestamp = (int) $timestampHeader;
        if (abs(now()->getTimestamp() - $timestamp) > $tolerance) {
            return response()->json([
                'message' => 'Timestamp webhook đã quá hạn cho phép.',
                'error_code' => 'WEBHOOK_TIMESTAMP_EXPIRED',
            ], 401);
        }

        $expectedSignature = hash_hmac('sha256', "{$timestampHeader}.{$rawBody}", $secret);
        if (! hash_equals($expectedSignature, $signatureHeader)) {
            return response()->json([
                'message' => 'Chữ ký HMAC-SHA256 webhook không hợp lệ.',
                'error_code' => 'WEBHOOK_SIGNATURE_INVALID',
            ], 401);
        }

        $validated = $request->validate([
            'event_id' => ['required', 'string', 'max:120'],
            'event_type' => ['required', 'string', 'in:CHARGE_SETTLED,CHARGE_FAILED,REFUND_SETTLED'],
            'provider_reference' => ['required', 'string', 'max:120'],
            'failure_code' => ['nullable', 'string', 'max:64'],
            'failure_message' => ['nullable', 'string', 'max:255'],
        ]);

        $provider = 'sandbox_epay';
        $eventId = trim((string) $validated['event_id']);
        $eventType = (string) $validated['event_type'];
        $providerRef = trim((string) $validated['provider_reference']);
        $payloadHash = hash('sha256', $rawBody);

        try {
            $result = DB::transaction(function () use (
                $provider,
                $eventId,
                $eventType,
                $providerRef,
                $payloadHash,
                $validated,
            ): array {
                $existing = GatewayWebhookEvent::query()
                    ->where('provider', $provider)
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return ['status' => 'already_processed'];
                }

                $event = GatewayWebhookEvent::create([
                    'provider' => $provider,
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'signature_valid' => true,
                    'payload_hash' => $payloadHash,
                    'processed_at' => now(),
                ]);

                // Resolve order_id without locking to enforce canonical lock order: orders -> payment_transactions
                $txLookup = PaymentTransaction::query()
                    ->where('provider', $provider)
                    ->where('provider_reference', $providerRef)
                    ->select(['id', 'order_id'])
                    ->first();

                if ($txLookup !== null) {
                    /** @var Order|null $lockedOrder */
                    $lockedOrder = $txLookup->order_id !== null
                        ? Order::query()->whereKey($txLookup->order_id)->lockForUpdate()->first()
                        : null;

                    /** @var PaymentTransaction|null $tx */
                    $tx = PaymentTransaction::query()
                        ->whereKey($txLookup->id)
                        ->lockForUpdate()
                        ->first();

                    if ($tx !== null) {
                        $isPendingCharge = $tx->type === 'CHARGE'
                            && in_array($tx->status, ['PENDING', 'TIMEOUT'], true);

                        if ($eventType === 'CHARGE_SETTLED' && $isPendingCharge) {
                            $tx->update([
                                'status' => 'SUCCEEDED',
                                'failure_code' => null,
                                'failure_message' => null,
                                'processed_at' => now(),
                            ]);

                            if ($lockedOrder !== null && ! in_array($lockedOrder->payment_status, [PaymentStatus::PAID, PaymentStatus::REFUNDED], true)) {
                                $nextPaymentStatus = $lockedOrder->status === OrderStatus::CANCELLED
                                    ? PaymentStatus::PENDING_RECONCILE
                                    : PaymentStatus::PAID;

                                $lockedOrder->update([
                                    'payment_status' => $nextPaymentStatus,
                                ]);
                            }
                        } elseif ($eventType === 'CHARGE_FAILED' && $isPendingCharge) {
                            $tx->update([
                                'status' => 'FAILED',
                                'failure_code' => $validated['failure_code'] ?? 'BANK_DECLINED',
                                'failure_message' => $validated['failure_message'] ?? 'Giao dịch thất bại theo thông báo từ ngân hàng.',
                                'processed_at' => now(),
                            ]);

                            if ($lockedOrder !== null && ! in_array($lockedOrder->payment_status, [PaymentStatus::PAID, PaymentStatus::REFUNDED], true)) {
                                $lockedOrder->update([
                                    'payment_status' => PaymentStatus::FAILED,
                                ]);
                            }
                        } elseif ($eventType === 'REFUND_SETTLED' && $tx->type === 'REFUND' && $tx->status !== 'SUCCEEDED') {
                            $tx->update([
                                'status' => 'SUCCEEDED',
                                'processed_at' => now(),
                            ]);
                        }
                    }
                }

                return [
                    'status' => 'processed',
                    'event_id' => $event->event_id,
                ];
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'already_processed']);
        }

        return response()->json($result);
    }
}
