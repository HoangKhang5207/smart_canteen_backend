<?php

namespace App\Services;

use App\Contracts\PushNotificationChannelInterface;
use App\Enums\NotificationSeverity;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\BankLink;
use App\Models\CanteenNotification;
use App\Models\Order;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    public const LOW_LIMIT_RATIO = 0.2;

    public function __construct(
        private readonly PushNotificationChannelInterface $pushChannel,
    ) {}

    /**
     * Persist an In-App notification and dispatch to the configured push channel.
     * Guaranteed fail-safe: exceptions are logged and never bubble into business transactions.
     *
     * @param  array<string, mixed>  $meta
     */
    public function send(
        User $recipient,
        NotificationType $type,
        NotificationSeverity $severity,
        string $title,
        string $body,
        ?string $actionUrl = null,
        array $meta = [],
    ): ?CanteenNotification {
        try {
            $safeActionUrl = $this->sanitizeInternalActionUrl($actionUrl);

            $notification = CanteenNotification::create([
                'user_id' => $recipient->id,
                'type' => $type,
                'severity' => $severity,
                'title' => mb_substr(trim($title), 0, 160),
                'body' => mb_substr(trim($body), 0, 500),
                'action_url' => $safeActionUrl,
                'meta' => $this->sanitizeMeta($meta),
                'push_channel' => $this->pushChannel->channelName(),
                'push_status' => 'SKIPPED',
                'push_reference' => null,
            ]);

            try {
                $delivery = $this->pushChannel->send($recipient, $notification);
                $notification->update([
                    'push_channel' => $delivery['channel'],
                    'push_status' => $delivery['status'],
                    'push_reference' => $delivery['reference'],
                ]);
            } catch (Throwable $pushError) {
                Log::warning('Notification push channel threw unexpected exception', [
                    'notification_id' => $notification->id,
                    'error' => mb_substr($pushError->getMessage(), 0, 180),
                ]);
                $notification->update(['push_status' => 'FAILED']);
            }

            return $notification->fresh();
        } catch (Throwable $e) {
            Log::warning('Failed to persist canteen notification', [
                'user_id' => $recipient->id,
                'type' => $type->value,
                'error' => mb_substr($e->getMessage(), 0, 180),
            ]);

            return null;
        }
    }

    /**
     * Schedule notification delivery after the enclosing DB transaction commits (or immediately if not in a transaction).
     *
     * @param  array<string, mixed>  $meta
     */
    public function sendAfterCommit(
        int $recipientUserId,
        NotificationType $type,
        NotificationSeverity $severity,
        string $title,
        string $body,
        ?string $actionUrl = null,
        array $meta = [],
    ): void {
        DB::afterCommit(function () use ($recipientUserId, $type, $severity, $title, $body, $actionUrl, $meta): void {
            $recipient = User::query()->find($recipientUserId);
            if ($recipient === null) {
                return;
            }
            $this->send($recipient, $type, $severity, $title, $body, $actionUrl, $meta);
        });
    }

    public function notifyPurchaseSuccess(Order $order, ?int $dailyLimit = null): void
    {
        $order->loadMissing(['studentCard.student']);
        $student = $order->studentCard?->student;
        $parentUserId = $student?->user_id ?? $order->user_id;
        $studentUserId = $student?->student_user_id;

        $amountFormatted = number_format((int) $order->total_amount, 0, ',', '.').' ₫';
        $remaining = $order->remaining_limit_snapshot;
        $remainingText = $remaining !== null
            ? sprintf(' Hạn mức còn lại hôm nay: %s ₫.', number_format((int) $remaining, 0, ',', '.'))
            : '';

        $body = $student !== null
            ? sprintf(
                'Học sinh %s vừa thanh toán đơn #%s trị giá %s tại %s.%s',
                $student->full_name,
                $order->code,
                $amountFormatted,
                $order->store_name ?: 'Căn tin Smart Canteen',
                $remainingText,
            )
            : sprintf(
                'Đơn hàng #%s trị giá %s tại %s đã được thanh toán thành công.',
                $order->code,
                $amountFormatted,
                $order->store_name ?: 'Căn tin Smart Canteen',
            );

        $meta = [
            'order_id' => $order->id,
            'order_code' => $order->code,
            'amount' => (int) $order->total_amount,
            'remaining_limit' => $remaining,
            'card_code' => $order->studentCard?->card_code,
        ];

        $this->sendAfterCommit(
            recipientUserId: (int) $parentUserId,
            type: NotificationType::PURCHASE_SUCCESS,
            severity: NotificationSeverity::SUCCESS,
            title: sprintf('Thanh toán thành công #%s (%s)', $order->code, $amountFormatted),
            body: $body,
            actionUrl: '/orders/'.$order->id,
            meta: $meta,
        );

        if ($studentUserId !== null && (int) $studentUserId !== (int) $parentUserId) {
            $this->sendAfterCommit(
                recipientUserId: (int) $studentUserId,
                type: NotificationType::PURCHASE_SUCCESS,
                severity: NotificationSeverity::SUCCESS,
                title: sprintf('Đã thanh toán đơn #%s (%s)', $order->code, $amountFormatted),
                body: $body,
                actionUrl: '/orders/'.$order->id,
                meta: $meta,
            );
        }

        // Trigger low spending limit alert when remaining <= 20% of daily_limit
        if (
            $student !== null
            && $dailyLimit !== null
            && $dailyLimit > 0
            && $remaining !== null
            && (int) $remaining <= (int) floor($dailyLimit * self::LOW_LIMIT_RATIO)
        ) {
            $this->sendAfterCommit(
                recipientUserId: (int) $parentUserId,
                type: NotificationType::LOW_SPENDING_LIMIT,
                severity: NotificationSeverity::WARNING,
                title: sprintf('Sắp chạm hạn mức chi tiêu (%s)', $student->full_name),
                body: sprintf(
                    'Thẻ %s chỉ còn %s ₫ / %s ₫ hạn mức chi tiêu hôm nay. Phụ huynh có thể điều chỉnh hạn mức trong Căn tin số.',
                    $order->studentCard?->card_code ?? '',
                    number_format((int) $remaining, 0, ',', '.'),
                    number_format($dailyLimit, 0, ',', '.'),
                ),
                actionUrl: '/canteen',
                meta: [
                    'student_card_id' => $order->student_card_id,
                    'card_code' => $order->studentCard?->card_code,
                    'daily_limit' => $dailyLimit,
                    'remaining_limit' => (int) $remaining,
                ],
            );
        }
    }

    public function notifyPaymentFailed(
        User $recipient,
        int $amount,
        string $errorCode,
        string $reason,
        ?int $orderId = null,
        ?int $studentCardId = null,
    ): void {
        $amountFormatted = number_format(max(0, $amount), 0, ',', '.').' ₫';
        $actionUrl = $orderId !== null ? '/orders/'.$orderId : '/canteen';

        $this->send(
            recipient: $recipient,
            type: NotificationType::PAYMENT_FAILED,
            severity: NotificationSeverity::ERROR,
            title: sprintf('Thanh toán không thành công (%s)', $amountFormatted),
            body: $reason,
            actionUrl: $actionUrl,
            meta: [
                'error_code' => $errorCode,
                'amount' => max(0, $amount),
                'order_id' => $orderId,
                'student_card_id' => $studentCardId,
            ],
        );
    }

    public function notifyOrderRefunded(Order $order): void
    {
        $order->loadMissing(['studentCard.student']);
        $parentUserId = $order->studentCard?->student?->user_id ?? $order->user_id;
        $amountFormatted = number_format((int) $order->total_amount, 0, ',', '.').' ₫';

        $this->sendAfterCommit(
            recipientUserId: (int) $parentUserId,
            type: NotificationType::ORDER_REFUNDED,
            severity: NotificationSeverity::INFO,
            title: sprintf('Đã hoàn tiền đơn #%s (%s)', $order->code, $amountFormatted),
            body: sprintf(
                'Đơn hàng #%s đã được hủy. Số tiền %s đã được hoàn về tài khoản ngân hàng liên kết và hạn mức ngày (nếu trong ngày).',
                $order->code,
                $amountFormatted,
            ),
            actionUrl: '/orders/'.$order->id,
            meta: [
                'order_id' => $order->id,
                'order_code' => $order->code,
                'amount' => (int) $order->total_amount,
            ],
        );
    }

    public function notifyOrderStatusUpdated(Order $order, OrderStatus $nextStatus): void
    {
        if (! in_array($nextStatus, [OrderStatus::READY, OrderStatus::CANCELLED], true)) {
            return;
        }

        $isPendingReconcileCancel = $nextStatus === OrderStatus::CANCELLED
            && $order->payment_status === PaymentStatus::PENDING_RECONCILE;

        $title = $nextStatus === OrderStatus::READY
            ? sprintf('Đơn #%s đã sẵn sàng nhận món', $order->code)
            : sprintf('Đơn #%s đã bị hủy', $order->code);
        $body = $nextStatus === OrderStatus::READY
            ? sprintf('Quầy căn tin đã chuẩn bị xong đơn #%s. Vui lòng đến quầy nhận món.', $order->code)
            : ($isPendingReconcileCancel
                ? sprintf('Đơn hàng #%s đã bị hủy. Khoản thanh toán VNPay đã được đánh dấu chờ đối soát hoàn tiền.', $order->code)
                : sprintf('Đơn hàng #%s đã được cập nhật trạng thái Hủy.', $order->code));

        $this->sendAfterCommit(
            recipientUserId: (int) $order->user_id,
            type: NotificationType::ORDER_STATUS_UPDATED,
            severity: $nextStatus === OrderStatus::READY ? NotificationSeverity::SUCCESS : NotificationSeverity::WARNING,
            title: $title,
            body: $body,
            actionUrl: '/orders/'.$order->id,
            meta: [
                'order_id' => $order->id,
                'order_code' => $order->code,
                'status' => $nextStatus->value,
            ],
        );
    }

    public function notifyBankLinkChanged(StudentCard $card, string $action, ?BankLink $bankLink = null): void
    {
        $card->loadMissing('student');
        $parentUserId = $card->student?->user_id;
        if ($parentUserId === null) {
            return;
        }

        $maskedAccount = $bankLink?->account_number_masked ?? '';
        $bankName = $bankLink?->bank_name ?? 'ngân hàng';

        [$severity, $title, $body] = match ($action) {
            'LINKED' => [
                NotificationSeverity::SUCCESS,
                sprintf('Liên kết ngân hàng thành công — Thẻ %s', $card->card_code),
                sprintf('Thẻ học sinh %s đã liên kết trích nợ tự động với %s (%s).', $card->card_code, $bankName, $maskedAccount),
            ],
            'UNLINKED' => [
                NotificationSeverity::WARNING,
                sprintf('Đã hủy liên kết ngân hàng — Thẻ %s', $card->card_code),
                sprintf('Thẻ học sinh %s đã ngắt liên kết tài khoản %s (%s).', $card->card_code, $bankName, $maskedAccount),
            ],
            'ACTIVE' => [
                NotificationSeverity::SUCCESS,
                sprintf('Đã mở khóa thẻ học sinh %s', $card->card_code),
                sprintf('Quản trị viên đã mở khóa thẻ học sinh %s trở lại trạng thái hoạt động (ACTIVE).', $card->card_code),
            ],
            default => [
                NotificationSeverity::WARNING,
                sprintf('Cập nhật trạng thái thẻ %s (%s)', $card->card_code, $action),
                sprintf('Quản trị viên đã chuyển trạng thái thẻ học sinh %s sang %s.', $card->card_code, $action),
            ],
        };

        $this->sendAfterCommit(
            recipientUserId: (int) $parentUserId,
            type: NotificationType::BANK_LINK_CHANGED,
            severity: $severity,
            title: $title,
            body: $body,
            actionUrl: '/canteen',
            meta: [
                'student_card_id' => $card->id,
                'card_code' => $card->card_code,
                'action' => $action,
                'account_number_masked' => $maskedAccount !== '' ? $maskedAccount : null,
            ],
        );
    }

    public function notifySpendingLimitUpdated(StudentCard $card, int $previousLimit, int $newLimit, string $actorName): void
    {
        $card->loadMissing('student');
        $parentUserId = $card->student?->user_id;
        if ($parentUserId === null) {
            return;
        }

        $prevFmt = number_format($previousLimit, 0, ',', '.').' ₫';
        $nextFmt = number_format($newLimit, 0, ',', '.').' ₫';
        $title = sprintf('Cập nhật hạn mức thẻ %s: %s/ngày', $card->card_code, $nextFmt);
        $body = sprintf(
            'Hạn mức chi tiêu hằng ngày của thẻ %s (%s) đã thay đổi từ %s sang %s bởi %s.',
            $card->card_code,
            $card->student->full_name,
            $prevFmt,
            $nextFmt,
            $actorName,
        );
        $meta = [
            'student_card_id' => $card->id,
            'card_code' => $card->card_code,
            'previous_limit' => $previousLimit,
            'new_limit' => $newLimit,
        ];

        $this->sendAfterCommit(
            recipientUserId: (int) $parentUserId,
            type: NotificationType::SPENDING_LIMIT_UPDATED,
            severity: NotificationSeverity::INFO,
            title: $title,
            body: $body,
            actionUrl: '/canteen/limit-history',
            meta: $meta,
        );

        $studentUserId = $card->student->student_user_id;
        if ($studentUserId !== null && (int) $studentUserId !== (int) $parentUserId) {
            $this->sendAfterCommit(
                recipientUserId: (int) $studentUserId,
                type: NotificationType::SPENDING_LIMIT_UPDATED,
                severity: NotificationSeverity::INFO,
                title: $title,
                body: $body,
                actionUrl: '/canteen/limit-history',
                meta: $meta,
            );
        }
    }

    private function sanitizeInternalActionUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $trimmed = trim($url);
        if ($trimmed === '' || preg_match('/^\/(?![\/\\\\])[^\s\x00-\x1f\x7f]*$/', $trimmed) !== 1) {
            return null;
        }

        return mb_substr($trimmed, 0, 160);
    }

    /**
     * Strip any forbidden PII/secret keys defensively before persisting meta JSON.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function sanitizeMeta(array $meta): array
    {
        $forbiddenKeys = [
            'account_number',
            'account_number_encrypted',
            'national_id',
            'national_id_encrypted',
            'phone',
            'phone_number',
            'mobile',
            'zalo_phone',
            'otp',
            'otp_code',
            'password',
            'token',
            'access_token',
            'refresh_token',
            'api_key',
            'secret',
            'secret_key',
            'hash_secret',
            'vnp_securehash',
        ];

        $clean = [];
        foreach ($meta as $key => $value) {
            $lowerKey = strtolower((string) $key);
            if (in_array($lowerKey, $forbiddenKeys, true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[(string) $key] = $value;
            }
        }

        return $clean;
    }
}
