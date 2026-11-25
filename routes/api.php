<?php

use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\StatisticsController as AdminStatisticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CanteenBankController;
use App\Http\Controllers\Api\V1\CanteenBankLinkController;
use App\Http\Controllers\Api\V1\CanteenCardController;
use App\Http\Controllers\Api\V1\CanteenPosPurchaseController;
use App\Http\Controllers\Api\V1\CanteenSpendingLimitController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::post('/auth/zalo', [AuthController::class, 'loginZalo']);
    Route::post('/auth/demo', [AuthController::class, 'loginDemo']);

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{id}', [ProductController::class, 'show'])->whereNumber('id');
    Route::get('/canteen/banks', [CanteenBankController::class, 'index']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{id}', [OrderController::class, 'show'])->whereNumber('id');

        Route::prefix('canteen')->group(function () {
            Route::get('/cards', [CanteenCardController::class, 'index']);
            Route::get('/cards/{cardId}', [CanteenCardController::class, 'show'])->whereNumber('cardId');
            Route::post('/cards/{cardId}/bank-links', [CanteenBankLinkController::class, 'store'])
                ->whereNumber('cardId')
                ->middleware('throttle:20,1');
            Route::post('/bank-links/{bankLinkId}/verify-otp', [CanteenBankLinkController::class, 'verifyOtp'])->whereNumber('bankLinkId');
            Route::post('/bank-links/{bankLinkId}/resend-otp', [CanteenBankLinkController::class, 'resendOtp'])->whereNumber('bankLinkId');

            Route::put('/cards/{cardId}/spending-limit', [CanteenSpendingLimitController::class, 'update'])->whereNumber('cardId');
            Route::get('/cards/{cardId}/limit-logs', [CanteenSpendingLimitController::class, 'logs'])->whereNumber('cardId');
            Route::get('/cards/{cardId}/orders', [CanteenSpendingLimitController::class, 'orders'])->whereNumber('cardId');
            Route::post('/cards/{cardId}/pos-purchases', [CanteenPosPurchaseController::class, 'store'])->whereNumber('cardId');
        });

        Route::prefix('admin')->middleware('role:staff,admin')->group(function () {
            Route::get('/orders', [AdminOrderController::class, 'index']);
            Route::patch('/orders/{id}/status', [AdminOrderController::class, 'updateStatus'])->whereNumber('id');
            Route::get('/statistics', [AdminStatisticsController::class, 'index']);
        });
    });
});
