<?php

use App\Http\Controllers\AdminCommissionRuleController;
use App\Http\Controllers\AdminTopupController;
use App\Http\Controllers\Api\BiteshipWebhookController;
use App\Http\Controllers\Api\ShippingController;
use App\Http\Controllers\MerchantOrderController;
use App\Http\Controllers\SupplierOrderController;
use App\Http\Controllers\SupplierWalletController;
use Illuminate\Support\Facades\Route;

$registerApiRoutes = function () {
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => 'petabahan-svc-order-finance',
            'timestamp' => now()->toISOString(),
        ]);
    });

    // Merchant Endpoints (/orders/*)
    Route::prefix('orders')->group(function () {
        Route::get('/', [MerchantOrderController::class, 'index']);
        Route::post('/', [MerchantOrderController::class, 'store']);
        Route::get('/{id}', [MerchantOrderController::class, 'show']);
        Route::post('/{id}/pay', [MerchantOrderController::class, 'pay']);
        Route::post('/{id}/settle', [MerchantOrderController::class, 'settle']);
        Route::post('/{id}/fulfillment', [ShippingController::class, 'setFulfillment']);
        Route::get('/{id}/shipping-quote', [ShippingController::class, 'getShippingQuote']);
        Route::post('/{id}/cancel', [MerchantOrderController::class, 'cancel']);
        Route::post('/{id}/complete', [MerchantOrderController::class, 'complete']);
    });

    // Supplier Endpoints (/supplier/*)
    Route::prefix('supplier')->group(function () {
        Route::prefix('orders')->group(function () {
            Route::get('/', [SupplierOrderController::class, 'index']);
            Route::get('/{id}', [SupplierOrderController::class, 'show']);
            Route::post('/{id}/accept', [SupplierOrderController::class, 'accept']);
            Route::post('/{id}/reject', [SupplierOrderController::class, 'reject']);
            Route::post('/{id}/payments/{pid}/confirm', [SupplierOrderController::class, 'confirmPayment']);
            Route::post('/{id}/payments/{pid}/reject', [SupplierOrderController::class, 'rejectPayment']);
            Route::get('/{id}/payments/{pid}/proof', [SupplierOrderController::class, 'proofSignedUrl']);
            Route::post('/{id}/call-courier', [ShippingController::class, 'callCourier']);
            Route::post('/{id}/handover', [ShippingController::class, 'handover']);
        });

        Route::prefix('wallet')->group(function () {
            Route::get('/', [SupplierWalletController::class, 'show']);
            Route::get('/transactions', [SupplierWalletController::class, 'transactions']);
            Route::post('/topups', [SupplierWalletController::class, 'requestTopup']);
        });
    });

    // Admin Endpoints (/admin/*)
    Route::prefix('admin')->group(function () {
        Route::prefix('topups')->group(function () {
            Route::get('/', [AdminTopupController::class, 'index']);
            Route::post('/{id}/approve', [AdminTopupController::class, 'approve']);
            Route::post('/{id}/reject', [AdminTopupController::class, 'reject']);
            Route::get('/{id}/proof', [AdminTopupController::class, 'proofSignedUrl']);
        });

        Route::prefix('commission-rules')->group(function () {
            Route::get('/', [AdminCommissionRuleController::class, 'index']);
            Route::post('/', [AdminCommissionRuleController::class, 'store']);
            Route::put('/{id}', [AdminCommissionRuleController::class, 'update']);
        });
    });

    // Biteship Public Webhook Route
    Route::post('/webhooks/biteship', [BiteshipWebhookController::class, 'handle'])
        ->name('api.webhooks.biteship');

    // Internal Saga Webhook Route
    Route::post('/internal/saga/compensate-stock-failure', function (\Illuminate\Http\Request $request) {
        $orderId = (int) $request->input('order_id');
        $reason = (string) $request->input('reason', 'Stok tidak mencukupi');
        event(new \App\Events\StockLockFailed($orderId, $reason));
        return response()->json(['status' => 'ok', 'message' => 'Compensation triggered']);
    });
};

// Register directly under /api/*
$registerApiRoutes();

// Register under /api/v1/* for versioned gateways
Route::prefix('v1')->group($registerApiRoutes);

// Signed Streaming Routes (Private Files)
Route::get('/orders/{order}/payments/{payment}/proof/stream', [SupplierOrderController::class, 'streamProof'])
    ->name('api.orders.payments.proof.show');

Route::get('/admin/topups/{id}/proof/stream', [AdminTopupController::class, 'streamProof'])
    ->name('api.admin.topups.proof.show');
