<?php

namespace App\Domain\Order\Services;

use App\Models\Order;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderExpirationService
{
    /**
     * Expire bookings whose SLA 30m has elapsed (BOOKING_REQUESTED -> EXPIRED)
     */
    public function expireBookingRequests(): int
    {
        $expiredCount = 0;
        $orders = Order::where('status', OrderStateMachine::STATE_BOOKING_REQUESTED)
            ->whereNotNull('booking_expires_at')
            ->where('booking_expires_at', '<', now())
            ->get();

        foreach ($orders as $order) {
            try {
                DB::transaction(function () use ($order) {
                    $locked = Order::where('id', $order->id)->lockForUpdate()->first();
                    if ($locked && $locked->status === OrderStateMachine::STATE_BOOKING_REQUESTED) {
                        OrderStateMachine::transition(
                            $locked,
                            OrderStateMachine::STATE_EXPIRED,
                            null,
                            'SLA konfirmasi Agen 30 menit kedaluwarsa (System Expiration)'
                        );
                    }
                });
                $expiredCount++;
            } catch (\Throwable $e) {
                Log::error("Failed to expire order {$order->id}: " . $e->getMessage());
            }
        }

        return $expiredCount;
    }

    /**
     * Expire accepted orders where first payment wasn't uploaded within 2h (ACCEPTED -> EXPIRED)
     */
    public function expireUnpaidAcceptedOrders(): int
    {
        $expiredCount = 0;
        $orders = Order::where('status', OrderStateMachine::STATE_ACCEPTED)
            ->whereNotNull('payment_expires_at')
            ->where('payment_expires_at', '<', now())
            ->get();

        foreach ($orders as $order) {
            try {
                DB::transaction(function () use ($order) {
                    $locked = Order::where('id', $order->id)->lockForUpdate()->first();
                    if ($locked && $locked->status === OrderStateMachine::STATE_ACCEPTED) {
                        // Release soft_hold reservations
                        StockReservation::where('order_id', $locked->id)
                            ->where('status', 'soft_hold')
                            ->update(['status' => 'released']);

                        OrderStateMachine::transition(
                            $locked,
                            OrderStateMachine::STATE_EXPIRED,
                            null,
                            'Batas waktu pembayaran 2 jam kedaluwarsa (System Expiration)'
                        );
                    }
                });
                $expiredCount++;
            } catch (\Throwable $e) {
                Log::error("Failed to expire accepted order {$order->id}: " . $e->getMessage());
            }
        }

        return $expiredCount;
    }

    /**
     * Cancel DP orders where settlement wasn't completed within 24h (PAID_DP -> CANCELLED)
     */
    public function expireUnsettledDpOrders(): int
    {
        $cancelledCount = 0;
        $orders = Order::where('status', OrderStateMachine::STATE_PAID_DP)
            ->whereNotNull('settlement_expires_at')
            ->where('settlement_expires_at', '<', now())
            ->get();

        foreach ($orders as $order) {
            try {
                DB::transaction(function () use ($order) {
                    $locked = Order::where('id', $order->id)->lockForUpdate()->first();
                    if ($locked && $locked->status === OrderStateMachine::STATE_PAID_DP) {
                        // Release reserved stock
                        StockReservation::where('order_id', $locked->id)
                            ->where('status', 'reserved')
                            ->update(['status' => 'released']);

                        OrderStateMachine::transition(
                            $locked,
                            OrderStateMachine::STATE_CANCELLED,
                            null,
                            'Batas waktu pelunasan 24 jam kedaluwarsa (System Expiration)'
                        );
                    }
                });
                $cancelledCount++;
            } catch (\Throwable $e) {
                Log::error("Failed to cancel unsettled DP order {$order->id}: " . $e->getMessage());
            }
        }

        return $cancelledCount;
    }

    /**
     * Run all expiration checks
     */
    public function runAll(): array
    {
        return [
            'expired_bookings' => $this->expireBookingRequests(),
            'expired_unpaid' => $this->expireUnpaidAcceptedOrders(),
            'cancelled_unsettled_dp' => $this->expireUnsettledDpOrders(),
        ];
    }
}
