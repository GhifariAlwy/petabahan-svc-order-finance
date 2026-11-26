<?php

namespace App\Domain\Order\Services;

use App\Exceptions\InvalidOrderStateException;
use App\Models\Order;
use App\Models\OrderStatusLog;

class OrderStateMachine
{
    public const STATE_DRAFT = 'DRAFT';
    public const STATE_BOOKING_REQUESTED = 'BOOKING_REQUESTED';
    public const STATE_ACCEPTED = 'ACCEPTED';
    public const STATE_REJECTED = 'REJECTED';
    public const STATE_EXPIRED = 'EXPIRED';
    public const STATE_PAYMENT_PROOF_UPLOADED = 'PAYMENT_PROOF_UPLOADED';
    public const STATE_PAID_DP = 'PAID_DP';
    public const STATE_SETTLEMENT_PROOF_UPLOADED = 'SETTLEMENT_PROOF_UPLOADED';
    public const STATE_PAID_FULL = 'PAID_FULL';
    public const STATE_CANCELLED = 'CANCELLED';
    public const STATE_READY_FOR_PICKUP = 'READY_FOR_PICKUP';
    public const STATE_COURIER_REQUESTED = 'COURIER_REQUESTED';
    public const STATE_IN_DELIVERY = 'IN_DELIVERY';
    public const STATE_DISPUTED = 'DISPUTED';
    public const STATE_COMPLETED = 'COMPLETED';

    public const ALL_STATES = [
        self::STATE_DRAFT,
        self::STATE_BOOKING_REQUESTED,
        self::STATE_ACCEPTED,
        self::STATE_REJECTED,
        self::STATE_EXPIRED,
        self::STATE_PAYMENT_PROOF_UPLOADED,
        self::STATE_PAID_DP,
        self::STATE_SETTLEMENT_PROOF_UPLOADED,
        self::STATE_PAID_FULL,
        self::STATE_CANCELLED,
        self::STATE_READY_FOR_PICKUP,
        self::STATE_COURIER_REQUESTED,
        self::STATE_IN_DELIVERY,
        self::STATE_DISPUTED,
        self::STATE_COMPLETED,
    ];

    /**
     * Permitted state transitions map: from_state => [allowed_to_states]
     */
    protected const TRANSITION_MAP = [
        self::STATE_DRAFT => [
            self::STATE_BOOKING_REQUESTED,
        ],
        self::STATE_BOOKING_REQUESTED => [
            self::STATE_ACCEPTED,
            self::STATE_REJECTED,
            self::STATE_EXPIRED,
        ],
        self::STATE_ACCEPTED => [
            self::STATE_PAYMENT_PROOF_UPLOADED,
            self::STATE_EXPIRED,
        ],
        self::STATE_REJECTED => [],
        self::STATE_EXPIRED => [],
        self::STATE_PAYMENT_PROOF_UPLOADED => [
            self::STATE_ACCEPTED, // When supplier rejects payment proof
            self::STATE_PAID_DP,
            self::STATE_PAID_FULL,
        ],
        self::STATE_PAID_DP => [
            self::STATE_SETTLEMENT_PROOF_UPLOADED,
            self::STATE_CANCELLED, // 24h settlement timeout or saga compensation
        ],
        self::STATE_SETTLEMENT_PROOF_UPLOADED => [
            self::STATE_PAID_DP, // When supplier rejects settlement proof
            self::STATE_PAID_FULL,
        ],
        self::STATE_PAID_FULL => [
            self::STATE_READY_FOR_PICKUP,
            self::STATE_COURIER_REQUESTED,
        ],
        self::STATE_CANCELLED => [],
        self::STATE_READY_FOR_PICKUP => [
            self::STATE_COMPLETED,
            self::STATE_DISPUTED,
        ],
        self::STATE_COURIER_REQUESTED => [
            self::STATE_IN_DELIVERY,
        ],
        self::STATE_IN_DELIVERY => [
            self::STATE_COMPLETED,
            self::STATE_DISPUTED,
        ],
        self::STATE_DISPUTED => [
            self::STATE_CANCELLED,
            self::STATE_COMPLETED,
        ],
        self::STATE_COMPLETED => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITION_MAP[$from] ?? [], true);
    }

    /**
     * Execute transition and write audit log
     *
     * @throws InvalidOrderStateException
     */
    public static function transition(Order $order, string $toStatus, ?int $actorId = null, ?string $note = null): Order
    {
        $fromStatus = $order->status;

        if (!self::canTransition($fromStatus, $toStatus)) {
            throw new InvalidOrderStateException(
                "Cannot transition order {$order->id} from {$fromStatus} to {$toStatus}"
            );
        }

        $order->status = $toStatus;
        $order->save();

        OrderStatusLog::create([
            'order_id' => $order->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => $actorId,
            'note' => $note,
            'created_at' => now(),
        ]);

        return $order;
    }
}
