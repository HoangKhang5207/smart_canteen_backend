<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CanteenCardManagementController;
use App\Http\Controllers\Admin\CategoryManagementController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderManagementController;
use App\Http\Controllers\Admin\PosTerminalController;
use App\Http\Controllers\Admin\ProductManagementController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\StudentManagementController;
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

        Route::get('/pos', [PosTerminalController::class, 'index'])->name('admin.pos.index');
        Route::get('/pos/lookup-card', [PosTerminalController::class, 'lookupCard'])->name('admin.pos.lookup');
        Route::post('/pos/checkout', [PosTerminalController::class, 'checkout'])->name('admin.pos.checkout');
        Route::get('/pos/receipt/{order}', [PosTerminalController::class, 'receipt'])
            ->whereNumber('order')
            ->name('admin.pos.receipt');

        Route::get('/orders', [OrderManagementController::class, 'index'])->name('admin.orders.index');
        Route::get('/orders/{order}', [OrderManagementController::class, 'show'])->name('admin.orders.show');
        Route::patch('/orders/{order}/status', [OrderManagementController::class, 'updateStatus'])->name('admin.orders.status');

        Route::get('/categories', [CategoryManagementController::class, 'index'])->name('admin.categories.index');
        Route::get('/products', [ProductManagementController::class, 'index'])->name('admin.products.index');

        Route::get('/canteen/students', [StudentManagementController::class, 'index'])->name('admin.canteen.students.index');
        Route::get('/canteen/cards', [CanteenCardManagementController::class, 'index'])->name('admin.canteen.cards.index');
        Route::get('/canteen/cards/{studentCard}', [CanteenCardManagementController::class, 'show'])
            ->whereNumber('studentCard')
            ->name('admin.canteen.cards.show');
        Route::get('/canteen/reconciliation', [ReconciliationController::class, 'index'])->name('admin.canteen.reconciliation.index');

        Route::middleware('role:admin')->group(function () {
            Route::get('/canteen/students/create', [StudentManagementController::class, 'create'])->name('admin.canteen.students.create');
            Route::post('/canteen/students', [StudentManagementController::class, 'store'])->name('admin.canteen.students.store');
            Route::get('/canteen/students/{student}/edit', [StudentManagementController::class, 'edit'])
                ->whereNumber('student')
                ->name('admin.canteen.students.edit');
            Route::put('/canteen/students/{student}', [StudentManagementController::class, 'update'])
                ->whereNumber('student')
                ->name('admin.canteen.students.update');
            Route::delete('/canteen/students/{student}', [StudentManagementController::class, 'destroy'])
                ->whereNumber('student')
                ->name('admin.canteen.students.destroy');
            Route::post('/canteen/students/{student}/cards', [StudentManagementController::class, 'issueCard'])
                ->whereNumber('student')
                ->name('admin.canteen.students.cards.store');

            Route::patch('/canteen/cards/{studentCard}/status', [CanteenCardManagementController::class, 'updateStatus'])
                ->whereNumber('studentCard')
                ->name('admin.canteen.cards.status');
            Route::put('/canteen/cards/{studentCard}/spending-limit', [CanteenCardManagementController::class, 'updateSpendingLimit'])
                ->whereNumber('studentCard')
                ->name('admin.canteen.cards.limit');
            Route::put('/canteen/cards/{studentCard}/sandbox-account', [CanteenCardManagementController::class, 'updateSandboxAccount'])
                ->whereNumber('studentCard')
                ->name('admin.canteen.cards.sandbox');
            Route::post('/canteen/reconciliation/run', [ReconciliationController::class, 'run'])->name('admin.canteen.reconciliation.run');
            Route::patch('/canteen/reconciliation/items/{item}/resolve', [ReconciliationController::class, 'resolveItem'])
                ->whereNumber('item')
                ->name('admin.canteen.reconciliation.resolve');
            Route::post('/categories', [CategoryManagementController::class, 'store'])->name('admin.categories.store');
            Route::put('/categories/{category}', [CategoryManagementController::class, 'update'])->name('admin.categories.update');
            Route::delete('/categories/{category}', [CategoryManagementController::class, 'destroy'])->name('admin.categories.destroy');

            Route::get('/products/create', [ProductManagementController::class, 'create'])->name('admin.products.create');
            Route::post('/products', [ProductManagementController::class, 'store'])->name('admin.products.store');
            Route::get('/products/{product}/edit', [ProductManagementController::class, 'edit'])->name('admin.products.edit');
            Route::put('/products/{product}', [ProductManagementController::class, 'update'])->name('admin.products.update');
            Route::patch('/products/{product}/stock', [ProductManagementController::class, 'updateStock'])
                ->whereNumber('product')
                ->name('admin.products.stock');
            Route::delete('/products/{product}', [ProductManagementController::class, 'destroy'])->name('admin.products.destroy');
        });
    });
});
