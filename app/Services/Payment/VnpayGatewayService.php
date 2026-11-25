<?php

namespace App\Services\Payment;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\SpendingLimitService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VnpayGatewayService
{
    private const LOCAL_SIMULATOR_TMN = 'SMARTCANTEEN';

    private const LOCAL_SIMULATOR_SECRET = 'LOCAL_VNPAY_SANDBOX_SECRET_KEY_2026';

    /**
     * @return array{tmn_code: string, hash_secret: string, payment_url: string, return_url: string, simulated: bool}
     */
    public function resolveConfig(): array
    {
        $tmnCode = trim((string) config('services.vnpay.tmn_code', ''));
        $hashSecret = trim((string) config('services.vnpay.hash_secret', ''));
        $paymentUrl = trim((string) config('services.vnpay.payment_url', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'));
        $returnUrl = trim((string) config('services.vnpay.return_url', 'http://localhost:8080/api/v1/payments/vnpay/return'));
        $simulatorAllowed = app()->environment(['local', 'testing'])
            && (bool) config('services.vnpay.sandbox_simulator_enabled', false);

        if ($tmnCode !== '' && $hashSecret !== '') {
            if (
                app()->isProduction()
                && ($hashSecret === self::LOCAL_SIMULATOR_SECRET || $tmnCode === self::LOCAL_SIMULATOR_TMN)
            ) {
                throw new PaymentGatewayException(
                    'Cấu hình VNPay đang sử dụng khóa mặc định cục bộ, không được phép trên môi trường production.',
                    'VNPAY_CONFIG_MISSING',
                    503,
                    'FAILED',
                );
            }

            return [
                'tmn_code' => $tmnCode,
                'hash_secret' => $hashSecret,
                'payment_url' => $paymentUrl,
                'return_url' => $returnUrl,
                'simulated' => false,
            ];
        }

        if ($simulatorAllowed) {
            return [
                'tmn_code' => self::LOCAL_SIMULATOR_TMN,
                'hash_secret' => self::LOCAL_SIMULATOR_SECRET,
                'payment_url' => $paymentUrl,
                'return_url' => $returnUrl,
                'simulated' => true,
            ];
        }

        throw new PaymentGatewayException(
            'Cổng thanh toán VNPay Sandbox chưa được cấu hình (VNPAY_TMN_CODE / VNPAY_HASH_SECRET).',
            'VNPAY_CONFIG_MISSING',
            503,
            'FAILED',
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function signParams(array $params, string $hashSecret): string
    {
        $filtered = [];
        foreach ($params as $key => $value) {
            $keyStr = (string) $key;
            if (! str_starts_with($keyStr, 'vnp_')) {
                continue;
            }
            if ($keyStr === 'vnp_SecureHash' || $keyStr === 'vnp_SecureHashType') {
                continue;
            }
            if (! is_scalar($value) || (string) $value === '') {
                continue;
            }
            $filtered[$keyStr] = (string) $value;
        }

        ksort($filtered);

        $hashParts = [];
        foreach ($filtered as $key => $value) {
            $hashParts[] = urlencode($key).'='.urlencode($value);
        }

        return hash_hmac('sha512', implode('&', $hashParts), $hashSecret);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function verifySignature(array $params, string $hashSecret): bool
    {
        $provided = $this->scalarParam($params, 'vnp_SecureHash');
        if ($provided === '') {
            return false;
        }

        $expected = $this->signParams($params, $hashSecret);

        return hash_equals(strtolower($expected), strtolower($provided));
    }

    /**
     * @return array{
     *     payment_url: string,
     *     txn_ref: string,
     *     amount: int,
     *     expires_at: string,
     *     simulated: bool
     * }
     */
    public function createPaymentUrl(User $user, int $orderId, string $clientIp): array
    {
        $cfg = $this->resolveConfig();
        $normalizedIp = filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ? $clientIp
            : '127.0.0.1';

        return DB::transaction(function () use ($user, $orderId, $normalizedIp, $cfg): array {
            /** @var Order|null $order */
            $order = Order::query()
                ->whereKey($orderId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw (new ModelNotFoundException)->setModel(Order::class, [$orderId]);
            }

            if (in_array($order->status, [OrderStatus::CANCELLED, OrderStatus::COMPLETED], true)) {
                throw ValidationException::withMessages([
                    'order_id' => ['Đơn hàng đã kết thúc hoặc bị hủy nên không thể thanh toán VNPay.'],
                ]);
            }

            if ($order->payment_method !== PaymentMethod::VNPAY) {
                throw ValidationException::withMessages([
                    'order_id' => ['Đơn hàng này không sử dụng phương thức thanh toán VNPay.'],
                ]);
            }

            if (! in_array($order->payment_status, [PaymentStatus::UNPAID, PaymentStatus::FAILED], true)) {
                throw ValidationException::withMessages([
                    'order_id' => ['Trạng thái thanh toán hiện tại của đơn hàng không cho phép tạo liên kết VNPay mới.'],
                ]);
            }

            $nowVn = now(SpendingLimitService::TIMEZONE);
            $expiresAt = $nowVn->copy()->addMinutes(15);
            $txnRef = sprintf('VNP-%d-%s', $order->id, Str::upper(Str::random(8)));

            PaymentTransaction::create([
                'order_id' => $order->id,
                'student_card_id' => null,
                'bank_link_id' => null,
                'user_id' => $user->id,
                'provider' => 'vnpay',
                'type' => 'CHARGE',
                'amount' => (int) $order->total_amount,
                'status' => 'PENDING',
                'gateway_txn_ref' => $txnRef,
                'response_meta' => [
                    'simulated' => $cfg['simulated'],
                ],
            ]);

            $params = [
                'vnp_Version' => '2.1.0',
                'vnp_TmnCode' => $cfg['tmn_code'],
                'vnp_Amount' => (string) ((int) $order->total_amount * 100),
                'vnp_Command' => 'pay',
                'vnp_CreateDate' => $nowVn->format('YmdHis'),
                'vnp_CurrCode' => 'VND',
                'vnp_IpAddr' => $normalizedIp,
                'vnp_Locale' => 'vn',
                'vnp_OrderInfo' => 'Thanh toan don hang '.$order->code,
                'vnp_OrderType' => 'other',
                'vnp_ReturnUrl' => $cfg['return_url'],
                'vnp_TxnRef' => $txnRef,
                'vnp_ExpireDate' => $expiresAt->format('YmdHis'),
            ];

            ksort($params);
            $secureHash = $this->signParams($params, $cfg['hash_secret']);
            $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC1738).'&vnp_SecureHash='.$secureHash;

            return [
                'payment_url' => $cfg['payment_url'].'?'.$queryString,
                'txn_ref' => $txnRef,
                'amount' => (int) $order->total_amount,
                'expires_at' => $expiresAt->toIso8601String(),
                'simulated' => $cfg['simulated'],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array{RspCode: string, Message: string}
     */
    public function handleIpn(array $queryParams): array
    {
        try {
            $cfg = $this->resolveConfig();
        } catch (PaymentGatewayException) {
            return ['RspCode' => '99', 'Message' => 'VNPay not configured'];
        }

        if (! $this->verifySignature($queryParams, $cfg['hash_secret'])) {
            return ['RspCode' => '97', 'Message' => 'Invalid signature'];
        }

        $tmnCode = $this->scalarParam($queryParams, 'vnp_TmnCode');
        if ($tmnCode === '' || ! hash_equals($cfg['tmn_code'], $tmnCode)) {
            return ['RspCode' => '97', 'Message' => 'Invalid merchant code'];
        }

        $txnRef = mb_substr($this->scalarParam($queryParams, 'vnp_TxnRef'), 0, 120);
        $rawAmountStr = $this->scalarParam($queryParams, 'vnp_Amount');
        $responseCode = mb_substr($this->scalarParam($queryParams, 'vnp_ResponseCode'), 0, 16);
        $transactionStatus = mb_substr($this->scalarParam($queryParams, 'vnp_TransactionStatus'), 0, 16);
        $transactionNo = mb_substr($this->scalarParam($queryParams, 'vnp_TransactionNo'), 0, 100);

        if ($txnRef === '') {
            return ['RspCode' => '01', 'Message' => 'Order not found'];
        }

        if ($rawAmountStr === '' || ! ctype_digit($rawAmountStr)) {
            return ['RspCode' => '04', 'Message' => 'Invalid amount'];
        }
        $rawAmount = (int) $rawAmountStr;

        // Resolve order_id without locking to enforce canonical lock order: orders -> payment_transactions
        $txLookup = PaymentTransaction::query()
            ->where('provider', 'vnpay')
            ->where('gateway_txn_ref', $txnRef)
            ->select(['id', 'order_id'])
            ->first();

        if ($txLookup === null) {
            return ['RspCode' => '01', 'Message' => 'Order not found'];
        }

        return DB::transaction(function () use (
            $txLookup,
            $txnRef,
            $rawAmount,
            $responseCode,
            $transactionStatus,
            $transactionNo,
        ): array {
            /** @var Order|null $order */
            $order = $txLookup->order_id !== null
                ? Order::query()->whereKey($txLookup->order_id)->lockForUpdate()->first()
                : null;

            /** @var PaymentTransaction|null $tx */
            $tx = PaymentTransaction::query()
                ->whereKey($txLookup->id)
                ->lockForUpdate()
                ->first();

            if ($tx === null) {
                return ['RspCode' => '01', 'Message' => 'Order not found'];
            }

            if ($rawAmount !== ((int) $tx->amount * 100)) {
                return ['RspCode' => '04', 'Message' => 'Invalid amount'];
            }

            if ($tx->status !== 'PENDING') {
                return ['RspCode' => '02', 'Message' => 'Order already confirmed'];
            }

            if ($order !== null && in_array($order->payment_status, [PaymentStatus::PAID, PaymentStatus::REFUNDED], true)) {
                return ['RspCode' => '02', 'Message' => 'Order already confirmed'];
            }

            $isSuccess = $responseCode === '00' && ($transactionStatus === '' || $transactionStatus === '00');
            $effectiveRef = $transactionNo !== '' ? $transactionNo : $txnRef;

            if ($isSuccess) {
                $tx->update([
                    'status' => 'SUCCEEDED',
                    'provider_reference' => $effectiveRef,
                    'failure_code' => null,
                    'failure_message' => null,
                    'response_meta' => array_merge($tx->response_meta ?? [], [
                        'vnp_ResponseCode' => $responseCode,
                        'vnp_TransactionStatus' => $transactionStatus !== '' ? $transactionStatus : null,
                        'vnp_TransactionNo' => $transactionNo !== '' ? $transactionNo : null,
                    ]),
                    'processed_at' => now(),
                ]);

                if ($order !== null) {
                    $nextPaymentStatus = $order->status === OrderStatus::CANCELLED
                        ? PaymentStatus::PENDING_RECONCILE
                        : PaymentStatus::PAID;

                    $order->update([
                        'payment_status' => $nextPaymentStatus,
                        'payment_reference' => $effectiveRef,
                    ]);
                }
            } else {
                $effectiveCode = $responseCode !== '' && $responseCode !== '00'
                    ? $responseCode
                    : ($transactionStatus !== '' ? $transactionStatus : 'UNKNOWN');

                $failureCode = $effectiveCode === '51' ? 'INSUFFICIENT_BANK_BALANCE' : 'VNPAY_FAILED_'.$effectiveCode;
                $failureMessage = match ($effectiveCode) {
                    '24' => 'Khách hàng đã hủy giao dịch thanh toán VNPay.',
                    '51' => 'Tài khoản ngân hàng không đủ số dư để thực hiện giao dịch VNPay.',
                    default => sprintf('Thanh toán VNPay thất bại (mã phản hồi: %s).', $effectiveCode),
                };

                $tx->update([
                    'status' => 'FAILED',
                    'provider_reference' => $effectiveRef,
                    'failure_code' => $failureCode,
                    'failure_message' => $failureMessage,
                    'response_meta' => array_merge($tx->response_meta ?? [], [
                        'vnp_ResponseCode' => $responseCode,
                        'vnp_TransactionStatus' => $transactionStatus !== '' ? $transactionStatus : null,
                        'vnp_TransactionNo' => $transactionNo !== '' ? $transactionNo : null,
                    ]),
                    'processed_at' => now(),
                ]);

                if (
                    $order !== null
                    && $order->status !== OrderStatus::CANCELLED
                    && ! in_array($order->payment_status, [PaymentStatus::PAID, PaymentStatus::REFUNDED, PaymentStatus::PENDING_RECONCILE], true)
                ) {
                    $order->update([
                        'payment_status' => PaymentStatus::FAILED,
                    ]);
                }
            }

            return ['RspCode' => '00', 'Message' => 'Confirm Success'];
        });
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array{valid: bool, success: bool, txn_ref: string, response_code: string, message: string}
     */
    public function verifyReturn(array $queryParams): array
    {
        $cfg = $this->resolveConfig();
        $valid = $this->verifySignature($queryParams, $cfg['hash_secret']);
        $tmnCode = $this->scalarParam($queryParams, 'vnp_TmnCode');
        $txnRef = mb_substr($this->scalarParam($queryParams, 'vnp_TxnRef'), 0, 120);
        $responseCode = mb_substr($this->scalarParam($queryParams, 'vnp_ResponseCode'), 0, 16);
        $transactionStatus = mb_substr($this->scalarParam($queryParams, 'vnp_TransactionStatus'), 0, 16);

        if (! $valid || ($tmnCode !== '' && ! hash_equals($cfg['tmn_code'], $tmnCode))) {
            return [
                'valid' => false,
                'success' => false,
                'txn_ref' => $txnRef,
                'response_code' => '97',
                'message' => 'Chữ ký phản hồi từ VNPay không hợp lệ.',
            ];
        }

        $success = $responseCode === '00' && ($transactionStatus === '' || $transactionStatus === '00');

        return [
            'valid' => true,
            'success' => $success,
            'txn_ref' => $txnRef,
            'response_code' => $responseCode,
            'message' => $success
                ? 'Thanh toán VNPay Sandbox thành công.'
                : sprintf('Thanh toán VNPay chưa hoàn tất (mã %s).', $responseCode !== '' ? $responseCode : $transactionStatus),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function scalarParam(array $params, string $key): string
    {
        if (! array_key_exists($key, $params) || ! is_scalar($params[$key])) {
            return '';
        }

        return trim((string) $params[$key]);
    }
}
