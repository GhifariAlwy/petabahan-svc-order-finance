<?php

use App\Domain\Finance\Services\DepositLedgerService;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Order\Services\OrderStateMachine;
use App\Domain\Shipping\Services\BiteshipClient;
use App\Domain\Shipping\Services\VehicleSelector;
use App\Exceptions\CourierApiException;
use App\Exceptions\InsufficientDepositException;
use App\Exceptions\InvalidOrderStateException;
use App\Models\DepositWallet;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->supplierId = 201;
    $this->merchantId = 301;

    // Create wallet with initial deposit
    DepositWallet::query()->delete();
    $this->wallet = DepositWallet::create([
        'supplier_id' => $this->supplierId,
        'balance' => 0,
        'min_required_balance' => 50000,
    ]);

    app(DepositLedgerService::class)->credit(
        $this->wallet,
        200000,
        'topup',
        'init:topup:' . uniqid(),
        null,
        'Initial balance for test'
    );
});

test('vehicle selector correctly identifies motorcycle vs car based on 20kg threshold', function () {
    $selector = app(VehicleSelector::class);

    // 19.9 kg -> Motorcycle
    $res1 = $selector->selectVehicle(19.9);
    expect($res1['vehicle_class'])->toBe(VehicleSelector::CLASS_MOTORCYCLE);

    // 20.0 kg -> Motorcycle (exact boundary)
    $res2 = $selector->selectVehicle(20.0);
    expect($res2['vehicle_class'])->toBe(VehicleSelector::CLASS_MOTORCYCLE);

    // 20.1 kg -> Car (threshold exceeded)
    $res3 = $selector->selectVehicle(20.1);
    expect($res3['vehicle_class'])->toBe(VehicleSelector::CLASS_CAR);
});

test('call courier is strictly forbidden before order is PAID_FULL', function () {
    // Order in PAID_DP
    $order = Order::create([
        'order_code' => 'ORD-TEST-DP',
        'merchant_id' => $this->merchantId,
        'supplier_id' => $this->supplierId,
        'status' => OrderStateMachine::STATE_PAID_DP,
        'subtotal_materials' => 100000,
        'merchant_fee' => 3500,
        'supplier_commission' => 3500,
        'total_commission' => 7000,
        'shipping_cost_estimate' => 20000,
        'grand_total_merchant' => 123500,
        'amount_paid' => 53500,
        'fulfillment_type' => 'courier',
    ]);

    $response = $this->postJson("/api/v1/supplier/orders/{$order->id}/call-courier", [
        'courier_company' => 'gosend',
        'shipping_fee_actual' => 20000,
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_ORDER_STATE');

    expect(Shipment::where('order_id', $order->id)->exists())->toBeFalse();
});

test('call courier rejects when supplier deposit is insufficient', function () {
    // Empty deposit wallet
    $this->wallet->update(['balance' => 5000]);

    $order = Order::create([
        'order_code' => 'ORD-TEST-INSUF',
        'merchant_id' => $this->merchantId,
        'supplier_id' => $this->supplierId,
        'status' => OrderStateMachine::STATE_PAID_FULL,
        'subtotal_materials' => 100000,
        'merchant_fee' => 3500,
        'supplier_commission' => 3500,
        'total_commission' => 7000,
        'shipping_cost_estimate' => 25000,
        'grand_total_merchant' => 128500,
        'amount_paid' => 128500,
        'fulfillment_type' => 'courier',
    ]);

    $response = $this->postJson("/api/v1/supplier/orders/{$order->id}/call-courier", [
        'courier_company' => 'gosend',
        'shipping_fee_actual' => 25000,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'INSUFFICIENT_DEPOSIT');
});

test('call courier successfully debits deposit and dispatches biteship courier', function () {
    Http::fake([
        '*/orders' => Http::response([
            'id' => 'bs_ord_mock_123',
            'status' => 'allocated',
            'courier' => [
                'waybill_id' => 'GOSEND-991283',
                'link' => 'https://biteship.com/track/GOSEND-991283',
                'driver_name' => 'Budi Driver',
                'driver_phone' => '08123456789',
                'vehicle_number' => 'B 5555 XYZ',
            ],
        ], 200),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-TEST-CALL',
        'merchant_id' => $this->merchantId,
        'supplier_id' => $this->supplierId,
        'status' => OrderStateMachine::STATE_PAID_FULL,
        'subtotal_materials' => 100000,
        'merchant_fee' => 3500,
        'supplier_commission' => 3500,
        'total_commission' => 7000,
        'shipping_cost_estimate' => 25000,
        'grand_total_merchant' => 128500,
        'amount_paid' => 128500,
        'fulfillment_type' => 'courier',
    ]);

    $response = $this->postJson("/api/v1/supplier/orders/{$order->id}/call-courier", [
        'courier_company' => 'gosend',
        'shipping_fee_actual' => 25000,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'COURIER_REQUESTED')
        ->assertJsonPath('data.shipment.waybill_id', 'GOSEND-991283')
        ->assertJsonPath('data.wallet.balance_after', 175000);

    // Verify order status
    expect($order->fresh()->status)->toBe('COURIER_REQUESTED');

    // Verify idempotency: calling again fails
    $retry = $this->postJson("/api/v1/supplier/orders/{$order->id}/call-courier", []);
    $retry->assertStatus(409);
});

test('automatic deposit rollback when biteship API fails', function () {
    Http::fake([
        '*/orders' => Http::response(['message' => 'Partner API internal error'], 500),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-TEST-FAIL',
        'merchant_id' => $this->merchantId,
        'supplier_id' => $this->supplierId,
        'status' => OrderStateMachine::STATE_PAID_FULL,
        'subtotal_materials' => 100000,
        'merchant_fee' => 3500,
        'supplier_commission' => 3500,
        'total_commission' => 7000,
        'shipping_cost_estimate' => 25000,
        'grand_total_merchant' => 128500,
        'amount_paid' => 128500,
        'fulfillment_type' => 'courier',
    ]);

    $balanceBefore = $this->wallet->fresh()->balance;

    $response = $this->postJson("/api/v1/supplier/orders/{$order->id}/call-courier", [
        'courier_company' => 'gosend',
        'shipping_fee_actual' => 25000,
    ]);

    $response->assertStatus(502)
        ->assertJsonPath('error.code', 'COURIER_API_FAILED');

    // Balance must be completely restored
    expect($this->wallet->fresh()->balance)->toBe($balanceBefore);
    expect($order->fresh()->status)->toBe(OrderStateMachine::STATE_PAID_FULL);
});

test('biteship webhook updates order status to IN_DELIVERY and COMPLETED', function () {
    $order = Order::create([
        'order_code' => 'ORD-TEST-WH',
        'merchant_id' => $this->merchantId,
        'supplier_id' => $this->supplierId,
        'status' => OrderStateMachine::STATE_COURIER_REQUESTED,
        'subtotal_materials' => 100000,
        'merchant_fee' => 3500,
        'supplier_commission' => 3500,
        'total_commission' => 7000,
        'shipping_cost_estimate' => 25000,
        'grand_total_merchant' => 128500,
        'amount_paid' => 128500,
        'fulfillment_type' => 'courier',
    ]);

    $shipment = Shipment::create([
        'order_id' => $order->id,
        'biteship_order_id' => 'bs_test_webhook_01',
        'biteship_tracking_id' => 'TRK-WH-01',
        'waybill_id' => 'WB-WH-01',
        'shipping_fee_actual' => 25000,
        'status' => 'allocated',
    ]);

    // 1. Status 'picked' -> Transitions to IN_DELIVERY
    $pickedResponse = $this->postJson('/api/v1/webhooks/biteship', [
        'event' => 'order.status_update',
        'event_id' => 'evt_test_01',
        'order_id' => 'bs_test_webhook_01',
        'status' => 'picked',
        'driver_name' => 'Joko Driver',
    ]);

    $pickedResponse->assertStatus(200);
    expect($order->fresh()->status)->toBe(OrderStateMachine::STATE_IN_DELIVERY);

    // 2. Status 'delivered' -> Transitions to COMPLETED
    $deliveredResponse = $this->postJson('/api/v1/webhooks/biteship', [
        'event' => 'order.status_update',
        'event_id' => 'evt_test_02',
        'order_id' => 'bs_test_webhook_01',
        'status' => 'delivered',
    ]);

    $deliveredResponse->assertStatus(200);
    expect($order->fresh()->status)->toBe(OrderStateMachine::STATE_COMPLETED);
    expect($shipment->fresh()->delivered_at)->not->toBeNull();
});

test('handover transitions order directly to COMPLETED for self_pickup', function () {
    $order = Order::create([
        'order_code' => 'ORD-TEST-HANDOVER',
        'merchant_id' => $this->merchantId,
        'supplier_id' => $this->supplierId,
        'status' => OrderStateMachine::STATE_PAID_FULL,
        'subtotal_materials' => 100000,
        'merchant_fee' => 3500,
        'supplier_commission' => 3500,
        'total_commission' => 7000,
        'grand_total_merchant' => 103500,
        'amount_paid' => 103500,
        'fulfillment_type' => 'self_pickup',
    ]);

    $response = $this->postJson("/api/v1/supplier/orders/{$order->id}/handover", [
        'notes' => 'Diambil langsung mandor di toko',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'COMPLETED');

    expect($order->fresh()->status)->toBe(OrderStateMachine::STATE_COMPLETED);
});
