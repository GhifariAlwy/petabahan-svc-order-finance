<?php

namespace App\Listeners;

use App\Domain\Finance\Services\DepositLedgerService;
use App\Domain\Order\Services\OrderStateMachine;
use App\Events\StockLockFailed;
use App\Models\DepositWallet;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompensateStockLockFailed
{
    public function __construct(
        protected DepositLedgerService $depositLedgerService
    ) {}

    public function handle(StockLockFailed $event): void
    {
        Log::warning("Saga Compensating triggered for order {$event->orderId}: {$event->reason}");

        DB::transaction(function () use ($event) {
            $order = Order::where('id', $event->orderId)->lockForUpdate()->first();
            if (!$order || $order->status === OrderStateMachine::STATE_CANCELLED) {
                return;
            }

            $wallet = DepositWallet::where('supplier_id', $order->supplier_id)->lockForUpdate()->first();
            if ($wallet) {
                // Auto refund commission idempotently
                $refundKey = "refund:commission:{$order->id}";
                $this->depositLedgerService->credit(
                    $wallet,
                    $order->total_commission,
                    'refund',
                    $refundKey,
                    $order->id,
                    "Kompensasi Saga: Auto-refund komisi karena stok gagal dikunci ({$event->reason})"
                );
            }

            $order->cancel_reason = "Saga Compensation: Stok gagal dikunci ({$event->reason})";
            $order->save();

            OrderStateMachine::transition(
                $order,
                OrderStateMachine::STATE_CANCELLED,
                null,
                "Saga Compensation: Stok gagal dikunci ({$event->reason})"
            );
        });
    }
}
