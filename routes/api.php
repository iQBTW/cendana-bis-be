<?php

use App\Http\Controllers\AccessClientController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\PaymentMethodsController;
use App\Http\Controllers\HistoryStockController;
use App\Http\Controllers\FinancialsController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\TransactionReportController;
use App\Http\Controllers\OutcomeReportController;
use App\Http\Controllers\CashFlowReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardAdminController;
use App\Http\Controllers\DashboardCashierController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TransactionsController;
use App\Http\Controllers\CompaniesController;
use App\Http\Controllers\HistoryReceiptController;
use App\Http\Controllers\BusinessHealthController;
use App\Http\Controllers\BusinessPerformanceController;
use App\Http\Controllers\SubscriptionPlansController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\MidtransNotificationController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\PlanFeatureController;
// use App\Http\Controllers\SubscriptionPaymentController;
use Illuminate\Support\Facades\Route;



Route::prefix('v1')->group(function () {
    Route::middleware('client.binding')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware(['throttle:5,1', 'recaptcha']);

        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/token/refresh', [AuthController::class, 'refreshAccessToken'])
            ->middleware('throttle:20,1');

        Route::middleware('auth:sanctum,api-client')->group(function () {
            Route::middleware('permission:access-client')->prefix('admin')->group(function () {
                Route::get('/access-client', [AccessClientController::class, 'index']);
                Route::post('/access-client', [AccessClientController::class, 'store'])->middleware('throttle:10,1');
                Route::delete('/access-client/{id}', [AccessClientController::class, 'revoke']);
                Route::post('/access-client/{id}/regenerate-secret', [AccessClientController::class, 'regenerateSecret'])->middleware('throttle:10,1');
            });
        });
    });
});
