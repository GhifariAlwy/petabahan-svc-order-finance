<?php

use App\Domain\Finance\Services\DepositLedgerService;
use App\Domain\Order\Services\BookingService;
use App\Domain\Order\Services\OrderStateMachine;
use App\Domain\Order\Services\PaymentService;
use App\Exceptions\InvalidOrderStateException;
use App\Exceptions\PaymentBelowMinDpException;
use App\Models\DepositWallet;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
});

it('DevSecOps [SEC-01]: ignores client-tampered price and fee payloads (enforces server-side recalculation)', function () {
    $bookingService = app(BookingService::class);

    DepositWallet::create([
        'supplier_id' => 501,
        'balance' => 100000,
        'min_required_balance' => 50000,
    ]);

    // Attacker tries to tamper payload by injecting 0 fee, 1000 total, 1 rupiah price
    $tamperedPayload = [
        'supplier_id' => 501,
        'payment_scheme' => 'dp',
        'subtotal_materials' => 10,       // Tampered!
        'merchant_fee' => 0,              // Tampered!
        'total_commission' => 0,          // Tampered!
        'grand_total_merchant' => 10,     // Tampered!
        'items' => [
            [
                'product_id' => 1,
                'material_name' => 'Semen Tiga Roda 50kg',
                'qty' => 10,
                'unit' => 'sak',
                'unit_price' => 70000, // Actual value = 700.000 -> Tier 3: comm 13.000 (fee 6.500)
            ]
        ]
    ];

    $order = $bookingService->createBooking($tamperedPayload, 1);

    // Verify server recalculated everything and completely discarded attacker's tampered values
    expect($order->subtotal_materials)->toBe(700000)
        ->and($order->merchant_fee)->toBe(6500)
        ->and($order->supplier_commission)->toBe(6500)
        ->and($order->total_commission)->toBe(13000)
        ->and($order->grand_total_merchant)->toBe(706500);
});

it('DevSecOps [SEC-02]: rejects DP 49% manipulation attempt with HTTP 422 PAYMENT_BELOW_MIN_DP', function () {
    $bookingService = app(BookingService::class);
    $paymentService = app(PaymentService::class);

    DepositWallet::create([
        'supplier_id' => 502,
        'balance' => 100000,
        'min_required_balance' => 50000,
    ]);

    $order = $bookingService->createBooking([
        'supplier_id' => 502,
        'payment_scheme' => 'dp',
        'items' => [
            [
                'product_id' => 1,
                'material_name' => 'Baja Ringan',
                'qty' => 10,
                'unit' => 'batang',
                'unit_price' => 100000, // 1.000.000 -> Tier 3: fee 6.500. Min DP = 506.500
            ]
        ]
    ], 1);

    $bookingService->acceptBooking($order, [], 502);

    $fakeProof = UploadedFile::fake()->create('proof.jpg', 200);

    // Attacker submits Rp 496.500 (49% materials + fee)
    expect(fn() => $paymentService->uploadFirstPayment($order, 496500, $fakeProof, 'dp', 'pickup', 1))
        ->toThrow(PaymentBelowMinDpException::class);
});

it('DevSecOps [SEC-03]: prevents replay attack on payment confirmation (idempotent commission debit)', function () {
    $bookingService = app(BookingService::class);
    $paymentService = app(PaymentService::class);

    $wallet = DepositWallet::create([
        'supplier_id' => 503,
        'balance' => 100000,
        'min_required_balance' => 50000,
    ]);

    $order = $bookingService->createBooking([
        'supplier_id' => 503,
        'payment_scheme' => 'dp',
        'items' => [
            [
                'product_id' => 1,
                'material_name' => 'Pasir 1 Truk',
                'qty' => 1,
                'unit' => 'truk',
                'unit_price' => 800000, // Tier 3: total comm 13.000
            ]
        ]
    ], 1);

    $bookingService->acceptBooking($order, [], 503);
    $fakeProof = UploadedFile::fake()->create('proof.jpg', 200);
    $payment = $paymentService->uploadFirstPayment($order, 406500, $fakeProof, 'dp', 'pickup', 1);

    // First confirmation: debits 13.000
    $paymentService->confirmFirstPayment($order->id, $payment->id, 503);
    expect($wallet->refresh()->balance)->toBe(87000);

    // Second confirmation (replay attack / duplicate network call)
    $paymentService->confirmFirstPayment($order->id, $payment->id, 503);

    // Balance MUST remain 87.000 (NOT debited to 74.000)
    expect($wallet->refresh()->balance)->toBe(87000);
});

it('DevSecOps [SEC-04]: blocks state machine bypass attempts with HTTP 409 INVALID_ORDER_STATE', function () {
    $order = Order::create([
        'order_code' => 'ORD-SEC-004',
        'merchant_id' => 1,
        'supplier_id' => 2,
        'status' => OrderStateMachine::STATE_BOOKING_REQUESTED,
    ]);

    // Attempting to jump directly to PAID_DP or COMPLETED
    expect(fn() => OrderStateMachine::transition($order, OrderStateMachine::STATE_PAID_DP))
        ->toThrow(InvalidOrderStateException::class);

    expect(fn() => OrderStateMachine::transition($order, OrderStateMachine::STATE_COMPLETED))
        ->toThrow(InvalidOrderStateException::class);
});

it('DevSecOps [SEC-05]: blocks private storage proof access without valid temporary signature', function () {
    $order = Order::create([
        'order_code' => 'ORD-SEC-005',
        'merchant_id' => 1,
        'supplier_id' => 2,
        'status' => 'PAYMENT_PROOF_UPLOADED',
    ]);

    $payment = OrderPayment::create([
        'order_id' => $order->id,
        'type' => 'first',
        'amount' => 500000,
        'proof_path' => 'proofs/payments/sample_secret.jpg',
        'status' => 'uploaded',
    ]);

    // Requesting stream endpoint directly without signed query parameter -> 403 Forbidden
    $response = $this->getJson("/api/orders/{$order->id}/payments/{$payment->id}/proof/stream");
    $response->assertStatus(403);
    $response->assertJsonPath('error.code', 'INVALID_SIGNATURE');
});

it('DevSecOps [SEC-06]: prevents IDOR on order endpoints (verifies merchant ownership)', function () {
    $order = Order::create([
        'order_code' => 'ORD-SEC-006',
        'merchant_id' => 10, // Belonging to Merchant #10
        'supplier_id' => 2,
        'status' => 'BOOKING_REQUESTED',
    ]);

    // Attacker sends request masquerading as Merchant #99
    $response = $this->withHeaders([
        'X-Merchant-Id' => '99',
        'X-User-Role' => 'merchant',
    ])->getJson("/api/orders/{$order->id}");

    $response->assertStatus(403);
    $response->assertJsonPath('error.code', 'FORBIDDEN_OWNERSHIP');
});
