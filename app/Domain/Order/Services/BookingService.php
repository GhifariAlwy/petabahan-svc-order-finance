<?php

namespace App\Domain\Order\Services;

use App\Domain\Finance\Services\CommissionCalculator;
use App\Domain\Finance\Services\DepositLedgerService;
use App\Models\DepositWallet;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        protected CommissionCalculator $commissionCalculator,
        protected DepositLedgerService $depositLedgerService
    ) {}

    /**
     * Create a new booking requested by Merchant
     *
     * @param array{
     *   supplier_id: int,
     *   shopping_list_id?: ?int,
     *   payment_scheme?: string,
     *   fulfillment_type?: string,
     *   items: array<array{product_id: int, material_name: string, qty: int, unit: string, unit_price: int, tier_id?: ?int}>,
     *   shipping_cost_estimate?: int,
     *   total_weight_kg?: float
     * } $data
     */
    public function createBooking(array $data, int $merchantId): Order
    {
        $supplierId = (int) $data['supplier_id'];

        // BR-7 / DEC-005: Supplier must have active deposit > min_required_balance to receive new bookings
        $wallet = DepositWallet::where('supplier_id', $supplierId)->first();
        if (!$wallet || $wallet->balance <= $wallet->min_required_balance) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Agen sedang tidak aktif menerima booking (saldo deposit berada di bawah atau sama dengan batas minimum Rp 50.000).'
            ]);
        }

        return DB::transaction(function () use ($data, $merchantId, $supplierId) {
            // Server-side calculation of subtotal
            $subtotalMaterials = 0;
            $itemsData = [];

            foreach ($data['items'] as $item) {
                $qty = (int) $item['qty'];
                $unitPrice = (int) $item['unit_price'];
                $lineTotal = $qty * $unitPrice;
                $subtotalMaterials += $lineTotal;

                $itemsData[] = [
                    'product_id' => $item['product_id'],
                    'material_name_snapshot' => $item['material_name'],
                    'qty' => $qty,
                    'unit' => $item['unit'],
                    'unit_price_applied' => $unitPrice,
                    'tier_id_applied' => $item['tier_id'] ?? null,
                    'line_total' => $lineTotal,
                    'is_available_confirmed' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Calculate commission 50:50
            $comm = $this->commissionCalculator->calculate($subtotalMaterials);
            $merchantFee = $comm['merchant_fee'];
            $supplierCommission = $comm['supplier_commission'];
            $totalCommission = $comm['total_commission'];

            $shippingEstimate = (int) ($data['shipping_cost_estimate'] ?? 0);
            $grandTotal = $subtotalMaterials + $merchantFee + $shippingEstimate;

            $orderCode = 'ORD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

            $order = Order::create([
                'order_code' => $orderCode,
                'merchant_id' => $merchantId,
                'supplier_id' => $supplierId,
                'shopping_list_id' => $data['shopping_list_id'] ?? null,
                'status' => OrderStateMachine::STATE_DRAFT,
                'payment_scheme' => $data['payment_scheme'] ?? 'dp',
                'fulfillment_type' => $data['fulfillment_type'] ?? 'pickup',
                'subtotal_materials' => $subtotalMaterials,
                'merchant_fee' => $merchantFee,
                'supplier_commission' => $supplierCommission,
                'total_commission' => $totalCommission,
                'shipping_cost_estimate' => $shippingEstimate,
                'grand_total_merchant' => $grandTotal,
                'amount_paid' => 0,
                'total_weight_kg' => $data['total_weight_kg'] ?? 0,
                'booking_expires_at' => now()->addMinutes(30), // SLA 30 minutes
            ]);

            foreach ($itemsData as $itemRow) {
                $itemRow['order_id'] = $order->id;
                OrderItem::create($itemRow);
            }

            // Transition from DRAFT to BOOKING_REQUESTED
            OrderStateMachine::transition($order, OrderStateMachine::STATE_BOOKING_REQUESTED, $merchantId, 'Booking diajukan oleh Merchant');

            return $order->load('items');
        });
    }

    /**
     * Supplier accepts booking (Supports Partial Accept BR-8)
     */
    public function acceptBooking(Order $order, array $unavailableItemIds = [], ?int $supplierId = null): Order
    {
        return DB::transaction(function () use ($order, $unavailableItemIds, $supplierId) {
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if (!empty($unavailableItemIds)) {
                // Mark unavailable items
                OrderItem::where('order_id', $lockedOrder->id)
                    ->whereIn('id', $unavailableItemIds)
                    ->update(['is_available_confirmed' => false]);

                // Recalculate subtotal from available items only
                $newSubtotal = (int) OrderItem::where('order_id', $lockedOrder->id)
                    ->where('is_available_confirmed', true)
                    ->sum('line_total');

                if ($newSubtotal <= 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Seluruh item ditolak ketersediaannya. Silakan tolak booking ini.'
                    ]);
                }

                // Recalculate commission
                $comm = $this->commissionCalculator->calculate($newSubtotal);
                $lockedOrder->subtotal_materials = $newSubtotal;
                $lockedOrder->merchant_fee = $comm['merchant_fee'];
                $lockedOrder->supplier_commission = $comm['supplier_commission'];
                $lockedOrder->total_commission = $comm['total_commission'];
                $lockedOrder->grand_total_merchant = $newSubtotal + $comm['merchant_fee'] + $lockedOrder->shipping_cost_estimate;
                $lockedOrder->save();
            }

            // Create stock reservations with status soft_hold
            $availableItems = OrderItem::where('order_id', $lockedOrder->id)
                ->where('is_available_confirmed', true)
                ->get();

            foreach ($availableItems as $item) {
                StockReservation::create([
                    'order_id' => $lockedOrder->id,
                    'product_id' => $item->product_id,
                    'qty' => $item->qty,
                    'status' => 'soft_hold',
                    'expires_at' => now()->addHours(2),
                ]);
            }

            $lockedOrder->accepted_at = now();
            $lockedOrder->payment_expires_at = now()->addHours(2); // 2 hours timer
            $lockedOrder->save();

            OrderStateMachine::transition(
                $lockedOrder,
                OrderStateMachine::STATE_ACCEPTED,
                $supplierId,
                empty($unavailableItemIds) ? 'Booking diterima penuh oleh Agen' : 'Booking diterima sebagian (Partial Accept) oleh Agen'
            );

            return $lockedOrder->load(['items', 'stockReservations']);
        });
    }

    /**
     * Supplier rejects booking
     */
    public function rejectBooking(Order $order, string $reason, ?int $supplierId = null): Order
    {
        return DB::transaction(function () use ($order, $reason, $supplierId) {
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();
            $lockedOrder->cancel_reason = $reason;
            $lockedOrder->save();

            OrderStateMachine::transition(
                $lockedOrder,
                OrderStateMachine::STATE_REJECTED,
                $supplierId,
                'Booking ditolak: ' . $reason
            );

            return $lockedOrder;
        });
    }
}
