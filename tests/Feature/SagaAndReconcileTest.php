<?php

use App\Domain\Order\Services\BookingService;
use App\Domain\Order\Services\PaymentService;
use App\Events\StockLockFailed;
use App\Models\DepositTransaction;
use App\Models\DepositWallet;
use App\Models\Order;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
});

it('handles Saga compensation StockLockFailed with idempotent auto-refund and cancels order', function () {
    $bookingService = app(BookingService::class);
    $paymentService = app(PaymentService::class);

    $wallet = DepositWallet::create([
        'supplier_id' => 201,
        'balance' => 0,
        'min_required_balance' => 50000,
    ]);

    // Top-up wallet via ledger so balance matches ledger transactions
    app(\App\Domain\Finance\Services\DepositLedgerService::class)->credit(
        $wallet,
        100000,
        'topup',
        'topup:initial:201',
        null,
        'Saldo awal agen'
    );
    $wallet->refresh();

    $order = $bookingService->createBooking([
        'supplier_id' => 201,
        'payment_scheme' => 'dp',
        'items' => [
            [
                'product_id' => 1,
                'material_name' => 'Keramik Granit 60x60',
                'qty' => 5,
                'unit' => 'dus',
                'unit_price' => 150000, // Subtotal 750.000 -> Tier 3: comm 13.000
            ]
        ]
    ], 1);

    $bookingService->acceptBooking($order, [], 201);
    $fakeProof = UploadedFile::fake()->create('proof.jpg', 500);
    $payment = $paymentService->uploadFirstPayment($order, 400000, $fakeProof, 'dp', 'pickup', 1);
    $paymentService->confirmFirstPayment($order->id, $payment->id, 201);

    expect($wallet->refresh()->balance)->toBe(87000); // 100k - 13k

    // Trigger Compensating Event: StockLockFailed
    event(new StockLockFailed($order->id, 'Stok fisik habis di gudang oleh pesanan offline'));

    // Check Auto-Refund & Cancel
    $wallet->refresh();
    $order->refresh();

    expect($wallet->balance)->toBe(100000) // 87k + 13k = 100k
        ->and($order->status)->toBe('CANCELLED');

    // Check ledger contains both commission debit and refund credit
    $refundTx = DepositTransaction::where('idempotency_key', "refund:commission:{$order->id}")->first();
    expect($refundTx)->not->toBeNull()
        ->and($refundTx->amount)->toBe(13000)
        ->and($refundTx->direction)->toBe('credit');

    // Run reconciliation command: Invariants must remain 0!
    $exitCode = Artisan::call('petabahan:reconcile');
    expect($exitCode)->toBe(0);
});

it('expires booking requests when SLA 30m is exceeded', function () {
    $bookingService = app(BookingService::class);

    DepositWallet::create([
        'supplier_id' => 202,
        'balance' => 100000,
        'min_required_balance' => 50000,
    ]);

    $order = $bookingService->createBooking([
        'supplier_id' => 202,
        'payment_scheme' => 'dp',
        'items' => [
            [
                'product_id' => 1,
                'material_name' => 'Pipa PVC 3 inch',
                'qty' => 2,
                'unit' => 'batang',
                'unit_price' => 80000,
            ]
        ]
    ], 1);

    // Time-travel: make booking_expires_at 35 minutes ago
    $order->booking_expires_at = now()->subMinutes(35);
    $order->save();

    Artisan::call('petabahan:expire-orders');

    expect($order->refresh()->status)->toBe('EXPIRED');
});

it('verifies reconciliation command catches ledger discrepancy', function () {
    // Fresh reconciliation is 0
    expect(Artisan::call('petabahan:reconcile'))->toBe(0);

    // Intentionally tamper a wallet balance without transaction
    $wallet = DepositWallet::create([
        'supplier_id' => 203,
        'balance' => 50000, // Balance has 50.000 but sum of transactions is 0!
        'min_required_balance' => 50000,
    ]);

    // Reconciliation must fail with exit code 1
    expect(Artisan::call('petabahan:reconcile'))->toBe(1);
});
