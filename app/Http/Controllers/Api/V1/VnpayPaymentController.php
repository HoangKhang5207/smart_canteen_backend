<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Services\Payment\VnpayGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VnpayPaymentController extends Controller
{
    public function createUrl(Request $request, VnpayGatewayService $vnpay): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $data = $vnpay->createPaymentUrl(
                user: $request->user(),
                orderId: (int) $validated['order_id'],
                clientIp: (string) ($request->ip() ?? '127.0.0.1'),
            );

            return response()->json(['data' => $data], 201);
        } catch (PaymentGatewayException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], $e->statusCode);
        }
    }

    public function ipn(Request $request, VnpayGatewayService $vnpay): JsonResponse
    {
        return response()->json($vnpay->handleIpn($request->query()));
    }

    public function returnUrl(Request $request, VnpayGatewayService $vnpay): JsonResponse
    {
        try {
            $result = $vnpay->verifyReturn($request->query());

            return response()->json(['data' => $result], $result['valid'] ? 200 : 400);
        } catch (PaymentGatewayException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], $e->statusCode);
        }
    }
}
