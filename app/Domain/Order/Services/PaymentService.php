<?php

namespace App\Domain\Order\Services;

use App\Domain\Finance\Services\DepositLedgerService;
use App\Events\OrderPaymentConfirmed;
use App\Exceptions\InsufficientDepositException;
use App\Exceptions\PaymentBelowMinDpException;
use App\Exceptions\PaymentInsufficientException;
use App\Models\DepositTransaction;
use App\Models\DepositWallet;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\StockReservation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        protected DepositLedgerService $depositLedgerService
    ) {}

    /**
     * Calculate minimum DP required for an order: ceil_int(subtotal * 0.5) + merchant_fee
     */
    public static function calculateMinDp(int $subtotalMaterials, int $merchantFee): int
    {
        return intdiv($subtotalMaterials + 1, 2) + $merchantFee;
    }

    /**
     * Merchant uploads first payment proof (DP or Full)
     */
    public function uploadFirstPayment(
        Order $order,
        int $amount,
        UploadedFile $proofFile,
        ?string $scheme = null,
        ?string $fulfillmentType = null,
        ?int $merchantId = null
    ): OrderPayment {
        return DB::transaction(function () use ($order, $amount, $proofFile, $scheme, $fulfillmentType, $merchantId) {
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($scheme && in_array($scheme, ['dp', 'full'], true)) {
                $lockedOrder->payment_scheme = $scheme;
            }
            if ($fulfillmentType && in_array($fulfillmentType, ['pickup', 'courier'], true)) {
                $lockedOrder->fulfillment_type = $fulfillmentType;
            }

            // Verify minimum payment required
            if ($lockedOrder->payment_scheme === 'dp') {
                $minRequired = self::calculateMinDp($lockedOrder->subtotal_materials, $lockedOrder->merchant_fee);
                if ($amount < $minRequired) {
                    throw new PaymentBelowMinDpException(
                        "Nominal pembayaran (Rp " . number_format($amount, 0, ',', '.') .
                        ") kurang dari batas minimum DP 50% + Biaya Platform (Rp " . number_format($minRequired, 0, ',', '.') . ")"
                    );
                }
            } else {
                // Skema Full
                $minRequired = $lockedOrder->subtotal_materials + $lockedOrder->merchant_fee +
                    ($lockedOrder->fulfillment_type === 'courier' ? $lockedOrder->shipping_cost_estimate : 0);
                if ($amount < $minRequired) {
                    throw new PaymentInsufficientException(
                        "Nominal pembayaran (Rp " . number_format($amount, 0, ',', '.') .
                        ") kurang dari total lunas (Rp " . number_format($minRequired, 0, ',', '.') . ")"
                    );
                }
            }

            $lockedOrder->save();

            // Store proof into private disk
            $filename = 'order_' . $lockedOrder->id . '_first_' . time() . '_' . Str::random(8) . '.' . $proofFile->getClientOriginalExtension();
            $path = $proofFile->storeAs('proofs/payments', $filename, 'private');

            $payment = OrderPayment::create([
                'order_id' => $lockedOrder->id,
                'type' => 'first',
                'amount' => $amount,
                'included_merchant_fee' => $lockedOrder->merchant_fee,
                'included_shipping' => ($lockedOrder->payment_scheme === 'full' && $lockedOrder->fulfillment_type === 'courier') ? $lockedOrder->shipping_cost_estimate : 0,
                'proof_path' => $path,
                'status' => 'uploaded',
            ]);

            OrderStateMachine::transition(
                $lockedOrder,
                OrderStateMachine::STATE_PAYMENT_PROOF_UPLOADED,
                $merchantId,
                'Bukti transfer pembayaran pertama diunggah oleh Merchant'
            );

            return $payment;
        });
    }

    /**
     * Supplier confirms first payment:
     * Deterministic lock: orders -> order_payments -> deposit_wallets
     * Atomic commission debit (100% of order commission)
     * Transition to PAID_DP or PAID_FULL
     */
    public function confirmFirstPayment(int $orderId, int $paymentId, int $supplierId): OrderPayment
    {
        return DB::transaction(function () use ($orderId, $paymentId, $supplierId) {
            // Anti-Deadlock Locking order: orders -> order_payments -> deposit_wallets
            $order = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();
            $payment = OrderPayment::where('id', $paymentId)->lockForUpdate()->firstOrFail();
            $wallet = DepositWallet::where('supplier_id', $order->supplier_id)->lockForUpdate()->firstOrFail();

            // Check if already confirmed (Idempotency)
            $idempotencyKey = "commission:{$order->id}";
            $existingTx = DepositTransaction::where('idempotency_key', $idempotencyKey)->first();

            if ($existingTx && $payment->status === 'confirmed') {
                return $payment;
            }

            // Verify order state
            if ($order->status !== OrderStateMachine::STATE_PAYMENT_PROOF_UPLOADED || $payment->status !== 'uploaded') {
                if (in_array($order->status, [OrderStateMachine::STATE_PAID_DP, OrderStateMachine::STATE_PAID_FULL], true)) {
                    return $payment; // Idempotent return
                }
            }

            // Debit commission atomically
            $this->depositLedgerService->debit(
                $wallet,
                $order->total_commission,
                'commission',
                $idempotencyKey,
                $order->id,
                "Potongan komisi 100% untuk pesanan {$order->order_code}"
            );

            // Update payment record
            $payment->status = 'confirmed';
            $payment->confirmed_by = $supplierId;
            $payment->confirmed_at = now();
            $payment->save();

            // Update order amounts and target status
            $order->amount_paid += $payment->amount;

            $targetStatus = ($order->payment_scheme === 'dp')
                ? OrderStateMachine::STATE_PAID_DP
                : OrderStateMachine::STATE_PAID_FULL;

            if ($targetStatus === OrderStateMachine::STATE_PAID_DP) {
                $order->settlement_expires_at = now()->addHours(24);
            }
            $order->save();

            // Transition stock reservations from soft_hold to reserved
            StockReservation::where('order_id', $order->id)
                ->where('status', 'soft_hold')
                ->update(['status' => 'reserved']);

            OrderStateMachine::transition(
                $order,
                $targetStatus,
                $supplierId,
                'Pembayaran pertama dikonfirmasi oleh Agen. Komisi dipotong penuh dari deposit.'
            );

            // Dispatch Saga Event
            try {
                event(new OrderPaymentConfirmed($order->id, $order->supplier_id));
            } catch (\Throwable $e) {
                // Event listener/queue fallback
            }

            return $payment;
        });
    }

    /**
     * Supplier rejects payment proof
     */
    public function rejectPayment(int $orderId, int $paymentId, string $reason, int $supplierId): OrderPayment
    {
        return DB::transaction(function () use ($orderId, $paymentId, $reason, $supplierId) {
            $order = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();
            $payment = OrderPayment::where('id', $paymentId)->lockForUpdate()->firstOrFail();

            $payment->status = 'rejected';
            $payment->reject_reason = $reason;
            $payment->save();

            $targetStatus = ($payment->type === 'first')
                ? OrderStateMachine::STATE_ACCEPTED
                : OrderStateMachine::STATE_PAID_DP;

            OrderStateMachine::transition(
                $order,
                $targetStatus,
                $supplierId,
                'Bukti pembayaran ditolak oleh Agen: ' . $reason
            );

            return $payment;
        });
    }

    /**
     * Merchant uploads settlement proof (Remaining 50% + shipping if courier)
     */
    public function uploadSettlement(
        Order $order,
        int $amount,
        UploadedFile $proofFile,
        ?int $merchantId = null
    ): OrderPayment {
        return DB::transaction(function () use ($order, $amount, $proofFile, $merchantId) {
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            $remainingMaterials = ($lockedOrder->subtotal_materials + $lockedOrder->merchant_fee) - $lockedOrder->amount_paid;
            $remainingShipping = ($lockedOrder->fulfillment_type === 'courier') ? $lockedOrder->shipping_cost_estimate : 0;
            $requiredSettlement = $remainingMaterials + $remainingShipping;

            if ($amount < $requiredSettlement) {
                throw new PaymentInsufficientException(
                    "Nominal pelunasan (Rp " . number_format($amount, 0, ',', '.') .
                    ") kurang dari sisa tagihan pelunasan (Rp " . number_format($requiredSettlement, 0, ',', '.') . ")"
                );
            }

            $filename = 'order_' . $lockedOrder->id . '_settlement_' . time() . '_' . Str::random(8) . '.' . $proofFile->getClientOriginalExtension();
            $path = $proofFile->storeAs('proofs/payments', $filename, 'private');

            $payment = OrderPayment::create([
                'order_id' => $lockedOrder->id,
                'type' => 'settlement',
                'amount' => $amount,
                'included_merchant_fee' => 0,
                'included_shipping' => $remainingShipping,
                'proof_path' => $path,
                'status' => 'uploaded',
            ]);

            OrderStateMachine::transition(
                $lockedOrder,
                OrderStateMachine::STATE_SETTLEMENT_PROOF_UPLOADED,
                $merchantId,
                'Bukti transfer pelunasan diunggah oleh Merchant'
            );

            return $payment;
        });
    }

    /**
     * Supplier confirms settlement payment (No commission deducted; already debited at DP)
     */
    public function confirmSettlement(int $orderId, int $paymentId, int $supplierId): OrderPayment
    {
        return DB::transaction(function () use ($orderId, $paymentId, $supplierId) {
            $order = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();
            $payment = OrderPayment::where('id', $paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->status === 'confirmed') {
                return $payment; // Idempotent
            }

            $payment->status = 'confirmed';
            $payment->confirmed_by = $supplierId;
            $payment->confirmed_at = now();
            $payment->save();

            $order->amount_paid += $payment->amount;
            $order->save();

            OrderStateMachine::transition(
                $order,
                OrderStateMachine::STATE_PAID_FULL,
                $supplierId,
                'Pelunasan dikonfirmasi oleh Agen. Pesanan resmi lunas (PAID_FULL).'
            );

            return $payment;
        });
    }

    /**
     * Get temporary signed URL for private proof file (10 minutes default)
     */
    public function getSignedProofUrl(OrderPayment $payment, int $minutes = 10): string
    {
        return URL::temporarySignedRoute(
            'api.orders.payments.proof.show',
            now()->addMinutes($minutes),
            ['order' => $payment->order_id, 'payment' => $payment->id]
        );
    }
}
