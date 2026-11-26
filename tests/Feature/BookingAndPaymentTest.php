<?php

use App\Domain\Finance\Services\DepositLedgerService;
use App\Domain\Order\Services\BookingService;
use App\Domain\Order\Services\PaymentService;
use App\Exceptions\InsufficientDepositException;
use App\Exceptions\PaymentBelowMinDpException;
use App\Models\DepositWallet;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('private');
});

it('validates BR-7: blocks booking creation when supplier deposit is insufficient', function () {
    $bookingService = app(BookingService::class);

    // Create supplier wallet with balance <= 50000 (minimum)
    DepositWallet::create([
        'supplier_id' => 99,
        'balance' => 50000,
        'min_required_balance' => 50000,
    ]);

    $payload = [
        'supplier_id' => 99,
        'payment_scheme' => 'dp',
        'items' => [
            [
                'product_id' => 10,
                'material_name' => 'Semen Padang 50kg',
                'qty' => 10,
                'unit' => 'sak',
                'unit_price' => 70000,
            ]
        ]
    ];

    expect(fn() => $bookingService->createBooking($payload, 1))
        ->toThrow(ValidationException::class);
});

it('creates booking with server-side pricing and commission calculation', function () {
    $bookingService = app(BookingService::class);

    DepositWallet::create([
        'supplier_id' => 100,
        'balance' => 100000,
        'min_required_balance' => 50000,
    ]);

    $payload = [
        'supplier_id' => 100,
        'payment_scheme' => 'dp',
        'items' => [
            [
                'product_id' => 10,
                'material_name' => 'Semen Padang 50kg',
                'qty' => 5,
                'unit' => 'sak',
                'unit_price' => 80000, // 400.000 -> Tier 2: 10.000 total (5.000 fee, 5.000 comm)
            ]
        ]
    ];

    $order = $bookingService->createBooking($payload, 1);

    expect($order->subtotal_materials)->toBe(400000)
        ->and($order->merchant_fee)->toBe(5000)
        ->and($order->supplier_commission)->toBe(5000)
        ->and($order->total_commission)->toBe(10000)
        ->and($order->status)->toBe('BOOKING_REQUESTED')
        ->and($order->booking_expires_at)->not->toBeNull();
});

it('supports partial accept and recalculates commission tier (BR-8)', function () {
    $bookingService = app(BookingService::class);

    DepositWallet::create([
        'supplier_id' => 101,
        'balance' => 150000,
        'min_required_balance' => 50000,
    ]);

    $payload = [
        'supplier_id' => 101,
        'payment_scheme' => 'dp',
        'items' => [
            [
                'product_id' => 1,
                'material_name' => 'Pasir 1 Truk',
                'qty' => 1,
                'unit' => 'truk',
                'unit_price' => 600000, // Tier 3
            ],
            [
                'product_id' => 2,
                'material_name' => 'Batu Bata 1000 pcs',
                'qty' => 1,
                'unit' => 'ikat',
                'unit_price' => 500000, // Total = 1.100.000 -> Tier 4 (15.000)
            ]
        ]
    ];

    $order = $bookingService->createBooking($payload, 1);
    expect($order->total_commission)->toBe(15000);

    // Supplier rejects item 2 (Batu Bata) -> subtotal drops to 600.000 -> Tier 3 (13.000)
    $batuBata = $order->items->firstWhere('product_id', 2);
    $acceptedOrder = $bookingService->acceptBooking($order, [$batuBata->id], 101);

    expect($acceptedOrder->subtotal_materials)->toBe(600000)
        ->and($acceptedOrder->total_commission)->toBe(13000)
        ->and($acceptedOrder->merchant_fee)->toBe(6500)
        ->and($acceptedOrder->supplier_commission)->toBe(6500)
        ->and($acceptedOrder->status)->toBe('ACCEPTED')
        ->and($acceptedOrder->stockReservations->count())->toBe(1);
});

it('validates DP 49% rejection vs DP 50% acceptance and enforces atomic confirmation', function () {
    $paymentService = app(PaymentService::class);
    $bookingService = app(BookingService::class);

    $wallet = DepositWallet::create([
        'supplier_id' => 102,
        'balance' => 100000,
        'min_required_balance' => 50000,
    ]);

    $order = $bookingService->createBooking([
        'supplier_id' => 102,
        'payment_scheme' => 'dp',
        'items' => [
            [
                'product_id' => 1,
                'material_name' => 'Baja Ringan',
                'qty' => 10,
                'unit' => 'batang',
                'unit_price' => 100000, // Subtotal 1.000.000 -> Tier 3: merchant fee 6.500
            ]
        ]
    ], 1);

    $bookingService->acceptBooking($order, [], 102);
    $order->refresh();

    // Min DP required: ceil(1.000.000 * 0.5) + 6.500 = 500.000 + 6.500 = 506.500
    // Test DP 49% (490.000 + 6.500 = 496.500) -> MUST FAIL
    $fakeProof = UploadedFile::fake()->create('proof.jpg', 500);

    expect(fn() => $paymentService->uploadFirstPayment($order, 496500, $fakeProof, 'dp', 'pickup', 1))
        ->toThrow(PaymentBelowMinDpException::class);

    // Test DP 50% exactly (506.500) -> MUST SUCCEED
    $payment = $paymentService->uploadFirstPayment($order, 506500, $fakeProof, 'dp', 'pickup', 1);
    expect($payment->status)->toBe('uploaded')
        ->and($order->refresh()->status)->toBe('PAYMENT_PROOF_UPLOADED');

    // Supplier confirms payment -> Debits 100% commission (13.000)
    $paymentService->confirmFirstPayment($order->id, $payment->id, 102);

    $wallet->refresh();
    $order->refresh();

    expect($order->status)->toBe('PAID_DP')
        ->and($wallet->balance)->toBe(87000) // 100.000 - 13.000
        ->and($order->amount_paid)->toBe(506500);

    // Idempotency: Confirming second time must NOT debit commission again
    $paymentService->confirmFirstPayment($order->id, $payment->id, 102);
    expect($wallet->refresh()->balance)->toBe(87000);
});

it('aborts and rolls back completely when supplier deposit is insufficient for commission', function () {
    $paymentService = app(PaymentService::class);
    $bookingService = app(BookingService::class);

    $wallet = DepositWallet::create([
        'supplier_id' => 103,
        'balance' => 5000, // Insufficient for Tier 1 commission (7.000)
        'min_required_balance' => 0,
    ]);

    $order = $bookingService->createBooking([
        'supplier_id' => 103,
        'payment_scheme' => 'dp',
        'items' => [
            [
                'product_id' => 1,
                'material_name' => 'Cat Tembok 5kg',
                'qty' => 1,
                'unit' => 'kaleng',
                'unit_price' => 100000, // Tier 1: total commission 7.000
            ]
        ]
    ], 1);

    $bookingService->acceptBooking($order, [], 103);
    $fakeProof = UploadedFile::fake()->create('proof.jpg', 500);
    $payment = $paymentService->uploadFirstPayment($order, 60000, $fakeProof, 'dp', 'pickup', 1);

    expect(fn() => $paymentService->confirmFirstPayment($order->id, $payment->id, 103))
        ->toThrow(InsufficientDepositException::class);

    // Verify complete rollback: wallet remains 5.000, order status remains PAYMENT_PROOF_UPLOADED
    expect($wallet->refresh()->balance)->toBe(5000)
        ->and($order->refresh()->status)->toBe('PAYMENT_PROOF_UPLOADED')
        ->and($payment->refresh()->status)->toBe('uploaded');
});
