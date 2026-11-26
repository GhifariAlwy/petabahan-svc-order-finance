<?php

use App\Domain\Order\Services\OrderStateMachine;
use App\Exceptions\InvalidOrderStateException;
use App\Models\Order;
use App\Models\OrderStatusLog;

it('verifies 15 states existence and legal transition matrix', function () {
    expect(count(OrderStateMachine::ALL_STATES))->toBe(15);

    $order = Order::create([
        'order_code' => 'ORD-TEST-001',
        'merchant_id' => 1,
        'supplier_id' => 2,
        'status' => OrderStateMachine::STATE_DRAFT,
    ]);

    // Valid: DRAFT -> BOOKING_REQUESTED
    OrderStateMachine::transition($order, OrderStateMachine::STATE_BOOKING_REQUESTED, 1, 'Draft submitted');
    expect($order->status)->toBe(OrderStateMachine::STATE_BOOKING_REQUESTED);

    // Valid: BOOKING_REQUESTED -> ACCEPTED
    OrderStateMachine::transition($order, OrderStateMachine::STATE_ACCEPTED, 2, 'Supplier accepted');
    expect($order->status)->toBe(OrderStateMachine::STATE_ACCEPTED);

    // Valid: ACCEPTED -> PAYMENT_PROOF_UPLOADED
    OrderStateMachine::transition($order, OrderStateMachine::STATE_PAYMENT_PROOF_UPLOADED, 1, 'Uploaded DP proof');
    expect($order->status)->toBe(OrderStateMachine::STATE_PAYMENT_PROOF_UPLOADED);

    // Valid: PAYMENT_PROOF_UPLOADED -> PAID_DP
    OrderStateMachine::transition($order, OrderStateMachine::STATE_PAID_DP, 2, 'Confirmed DP');
    expect($order->status)->toBe(OrderStateMachine::STATE_PAID_DP);

    // Valid: PAID_DP -> SETTLEMENT_PROOF_UPLOADED
    OrderStateMachine::transition($order, OrderStateMachine::STATE_SETTLEMENT_PROOF_UPLOADED, 1, 'Uploaded settlement proof');
    expect($order->status)->toBe(OrderStateMachine::STATE_SETTLEMENT_PROOF_UPLOADED);

    // Valid: SETTLEMENT_PROOF_UPLOADED -> PAID_FULL
    OrderStateMachine::transition($order, OrderStateMachine::STATE_PAID_FULL, 2, 'Confirmed settlement');
    expect($order->status)->toBe(OrderStateMachine::STATE_PAID_FULL);

    // Valid: PAID_FULL -> READY_FOR_PICKUP
    OrderStateMachine::transition($order, OrderStateMachine::STATE_READY_FOR_PICKUP, 2, 'Ready for pickup');
    expect($order->status)->toBe(OrderStateMachine::STATE_READY_FOR_PICKUP);

    // Valid: READY_FOR_PICKUP -> COMPLETED
    OrderStateMachine::transition($order, OrderStateMachine::STATE_COMPLETED, 1, 'Handed over');
    expect($order->status)->toBe(OrderStateMachine::STATE_COMPLETED);

    // Verify audit logs created
    expect(OrderStatusLog::where('order_id', $order->id)->count())->toBe(8);
});

it('rejects illegal state transitions with 409 InvalidOrderStateException', function () {
    $order = Order::create([
        'order_code' => 'ORD-TEST-002',
        'merchant_id' => 1,
        'supplier_id' => 2,
        'status' => OrderStateMachine::STATE_DRAFT,
    ]);

    // Illegal: DRAFT directly to PAID_FULL
    expect(fn() => OrderStateMachine::transition($order, OrderStateMachine::STATE_PAID_FULL))
        ->toThrow(InvalidOrderStateException::class);

    // Illegal: DRAFT to COMPLETED
    expect(fn() => OrderStateMachine::transition($order, OrderStateMachine::STATE_COMPLETED))
        ->toThrow(InvalidOrderStateException::class);
});
