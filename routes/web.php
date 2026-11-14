<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoryManagementController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderManagementController;
use App\Http\Controllers\Admin\ProductManagementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('admin.login');
    Route::post('/login', [AuthController::class, 'login'])->name('admin.login.submit');
    Route::post('/logout', [AuthController::class, 'logout'])->name('admin.logout');

    Route::middleware(['auth', 'role:staff,admin'])->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');

        Route::get('/orders', [OrderManagementController::class, 'index'])->name('admin.orders.index');
        Route::get('/orders/{order}', [OrderManagementController::class, 'show'])->name('admin.orders.show');
        Route::patch('/orders/{order}/status', [OrderManagementController::class, 'updateStatus'])->name('admin.orders.status');

        Route::get('/categories', [CategoryManagementController::class, 'index'])->name('admin.categories.index');
        Route::post('/categories', [CategoryManagementController::class, 'store'])->name('admin.categories.store');
        Route::put('/categories/{category}', [CategoryManagementController::class, 'update'])->name('admin.categories.update');
        Route::delete('/categories/{category}', [CategoryManagementController::class, 'destroy'])->name('admin.categories.destroy');

        Route::get('/products', [ProductManagementController::class, 'index'])->name('admin.products.index');
        Route::get('/products/create', [ProductManagementController::class, 'create'])->name('admin.products.create');
        Route::post('/products', [ProductManagementController::class, 'store'])->name('admin.products.store');
        Route::get('/products/{product}/edit', [ProductManagementController::class, 'edit'])->name('admin.products.edit');
        Route::put('/products/{product}', [ProductManagementController::class, 'update'])->name('admin.products.update');
        Route::delete('/products/{product}', [ProductManagementController::class, 'destroy'])->name('admin.products.destroy');
    });
});
