<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BankLinkConflictException;
use App\Exceptions\OtpVerificationException;
use App\Exceptions\SmsDeliveryException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InitiateBankLinkRequest;
use App\Http\Requests\VerifyBankLinkOtpRequest;
use App\Http\Resources\StudentCardResource;
use App\Services\BankLinkingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CanteenBankLinkController extends Controller
{
    public function store(
        InitiateBankLinkRequest $request,
        int $cardId,
        BankLinkingService $bankLinkingService,
    ): JsonResponse {
        try {
            $data = $bankLinkingService->initiateLink(
                user: $request->user(),
                cardId: $cardId,
                payload: $request->validated(),
            );

            return response()->json(['data' => $data], 201);
        } catch (BankLinkConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], 409);
        } catch (SmsDeliveryException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], $e->statusCode);
        }
    }

    public function verifyOtp(
        VerifyBankLinkOtpRequest $request,
        int $bankLinkId,
        BankLinkingService $bankLinkingService,
    ): JsonResponse|StudentCardResource {
        try {
            $card = $bankLinkingService->verifyOtp(
                user: $request->user(),
                bankLinkId: $bankLinkId,
                otpCode: (string) $request->validated('otp_code'),
            );

            return new StudentCardResource($card);
        } catch (BankLinkConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], 409);
        } catch (OtpVerificationException $e) {
            $body = [
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ];
            if ($e->meta !== []) {
                $body['meta'] = $e->meta;
            }

            return response()->json($body, $e->statusCode);
        }
    }

    public function resendOtp(
        Request $request,
        int $bankLinkId,
        BankLinkingService $bankLinkingService,
    ): JsonResponse {
        try {
            $data = $bankLinkingService->resendOtp(
                user: $request->user(),
                bankLinkId: $bankLinkId,
            );

            return response()->json(['data' => $data]);
        } catch (BankLinkConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], 409);
        } catch (OtpVerificationException $e) {
            $body = [
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ];
            if ($e->meta !== []) {
                $body['meta'] = $e->meta;
            }

            return response()->json($body, $e->statusCode);
        } catch (SmsDeliveryException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], $e->statusCode);
        }
    }

    public function initiateUnlink(
        Request $request,
        int $bankLinkId,
        BankLinkingService $bankLinkingService,
    ): JsonResponse {
        try {
            $data = $bankLinkingService->initiateUnlink(
                user: $request->user(),
                bankLinkId: $bankLinkId,
            );

            return response()->json(['data' => $data], 201);
        } catch (BankLinkConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], 409);
        } catch (OtpVerificationException $e) {
            $body = [
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ];
            if ($e->meta !== []) {
                $body['meta'] = $e->meta;
            }

            return response()->json($body, $e->statusCode);
        } catch (SmsDeliveryException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], $e->statusCode);
        }
    }

    public function verifyUnlinkOtp(
        VerifyBankLinkOtpRequest $request,
        int $bankLinkId,
        BankLinkingService $bankLinkingService,
    ): JsonResponse|StudentCardResource {
        try {
            $card = $bankLinkingService->verifyUnlinkOtp(
                user: $request->user(),
                bankLinkId: $bankLinkId,
                otpCode: (string) $request->validated('otp_code'),
            );

            return new StudentCardResource($card);
        } catch (BankLinkConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], 409);
        } catch (OtpVerificationException $e) {
            $body = [
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ];
            if ($e->meta !== []) {
                $body['meta'] = $e->meta;
            }

            return response()->json($body, $e->statusCode);
        }
    }

    public function resendUnlinkOtp(
        Request $request,
        int $bankLinkId,
        BankLinkingService $bankLinkingService,
    ): JsonResponse {
        try {
            $data = $bankLinkingService->resendUnlinkOtp(
                user: $request->user(),
                bankLinkId: $bankLinkId,
            );

            return response()->json(['data' => $data]);
        } catch (BankLinkConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], 409);
        } catch (OtpVerificationException $e) {
            $body = [
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ];
            if ($e->meta !== []) {
                $body['meta'] = $e->meta;
            }

            return response()->json($body, $e->statusCode);
        } catch (SmsDeliveryException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], $e->statusCode);
        }
    }
}
