<?php

namespace App\Domain\Shipping\Services;

use App\Domain\Finance\Services\DepositLedgerService;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Order\Services\OrderStateMachine;
use App\Exceptions\CourierApiException;
use App\Exceptions\InsufficientDepositException;
use App\Exceptions\InvalidOrderStateException;
use App\Models\DepositWallet;
use App\Models\Order;
use App\Models\Shipment;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShippingService
{
    public function __construct(
        protected BiteshipClient $biteshipClient,
        protected VehicleSelector $vehicleSelector,
        protected DepositLedgerService $depositLedgerService,
        protected OrderStateMachine $orderStateMachine,
        protected NotificationService $notificationService
    ) {}

    /**
     * Set order fulfillment mode (courier vs self_pickup).
     */
    public function setFulfillment(Order $order, string $type, array $addressData = []): Order
    {
        $order->fulfillment_type = $type;

        if ($type === 'courier' && !empty($addressData)) {
            $totalWeight = $this->vehicleSelector->calculateOrderWeight($order);
            $vehicleRecommendation = $this->vehicleSelector->selectVehicle($totalWeight);

            Shipment::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'vehicle_class' => $vehicleRecommendation['vehicle_class'],
                    'status' => 'pending',
                    'shipping_fee_actual' => $order->shipping_cost_estimate ?? 0,
                ]
            );
        }

        $order->save();
        return $order->fresh(['shipment']);
    }

    /**
     * Get real-time instant shipping rate quotes from Biteship.
     */
    public function getShippingQuote(Order $order): array
    {
        $totalWeight = $this->vehicleSelector->calculateOrderWeight($order);
        $vehicleRecommendation = $this->vehicleSelector->selectVehicle($totalWeight);

        $origin = [
            'latitude' => -6.2088, // Toko / supplier location
            'longitude' => 106.8456,
        ];

        $destination = [
            'latitude' => -6.2150, // Project / merchant location
            'longitude' => 106.8550,
        ];

        $items = [];
        foreach ($order->items as $item) {
            $items[] = [
                'name' => $item->material_name ?? 'Material Bangunan',
                'description' => 'Bahan Baku Bangunan',
                'value' => (int) $item->unit_price,
                'length' => 30,
                'width' => 20,
                'height' => 10,
                'weight' => (int) round(($item->weight_per_unit_kg ?? 1.0) * 1000), // in grams
                'quantity' => (int) $item->qty,
            ];
        }

        $rates = $this->biteshipClient->getRates($origin, $destination, $items);

        // Filter couriers matching allowed vehicle classes (e.g. if > 20kg exclude motorcycle)
        $filteredRates = [];
        foreach ($rates as $rate) {
            $vehicleType = strtolower($rate['vehicle_type'] ?? $rate['type'] ?? '');
            if ($vehicleRecommendation['vehicle_class'] === VehicleSelector::CLASS_CAR) {
                // If weight > 20kg, exclude motor
                if (str_contains($vehicleType, 'bike') || str_contains($vehicleType, 'motor')) {
                    continue;
                }
            }
            $filteredRates[] = $rate;
        }

        return [
            'order_id' => $order->id,
            'total_weight_kg' => $totalWeight,
            'vehicle_class' => $vehicleRecommendation['vehicle_class'],
            'vehicle_recommendation' => $vehicleRecommendation,
            'rates' => !empty($filteredRates) ? $filteredRates : $rates,
        ];
    }

    /**
     * Call courier via Biteship partner API.
     *
     * @throws InvalidOrderStateException
     * @throws InsufficientDepositException
     * @throws CourierApiException
     */
    public function callCourier(Order $order, array $courierData): array
    {
        // 1. Strict Gate: Must be PAID_FULL (BR-6 & FR-G3)
        if ($order->status !== OrderStateMachine::STATE_PAID_FULL) {
            throw new InvalidOrderStateException(
                "Kurir hanya dapat dipanggil setelah pesanan berstatus PAID_FULL. Status saat ini: {$order->status}",
                409
            );
        }

        // 2. Prevent duplicate courier dispatch
        $existingShipment = Shipment::where('order_id', $order->id)->first();
        if ($existingShipment && !empty($existingShipment->biteship_order_id)) {
            throw new InvalidOrderStateException('Kurir untuk pesanan ini sudah pernah dipanggil sebelumnya.', 409);
        }

        $actualFee = (int) ($courierData['shipping_fee_actual'] ?? $order->shipping_cost_estimate ?? 25000);
        $courierCompany = $courierData['courier_company'] ?? 'gosend';
        $courierType = $courierData['courier_type'] ?? 'instant';

        // 3. Check and debit deposit wallet atomically
        $wallet = DepositWallet::where('supplier_id', $order->supplier_id)->first();
        $balanceBefore = $wallet ? $wallet->balance : 0;

        if (!$wallet || $balanceBefore < $actualFee) {
            throw new InsufficientDepositException(
                "Saldo deposit agen (Rp " . number_format($balanceBefore, 0, ',', '.') . ") tidak mencukupi untuk biaya kurir aktual (Rp " . number_format($actualFee, 0, ',', '.') . ").",
                422
            );
        }

        // Debit courier fee
        $idempotencyKey = "courier:{$order->id}";
        $this->depositLedgerService->debit(
            wallet: $wallet,
            amount: $actualFee,
            type: 'courier_fee',
            idempotencyKey: $idempotencyKey,
            orderId: $order->id,
            note: "Ongkir kurir pengiriman pesanan #{$order->order_code}"
        );

        // 4. Call Biteship Create Order API
        $orderPayload = [
            'shipper_contact_name' => 'Toko Bahan Mitra',
            'shipper_contact_phone' => '081234567890',
            'origin_address' => 'Gudang Toko Bahan Bangunan Mitra',
            'destination_contact_name' => 'Mandor Proyek',
            'destination_contact_phone' => '081298765432',
            'destination_address' => 'Lokasi Proyek Konstruksi',
            'courier_company' => $courierCompany,
            'courier_type' => $courierType,
            'delivery_type' => 'now',
            'items' => [
                [
                    'name' => 'Bahan Bangunan Order #' . $order->order_code,
                    'value' => (int) $order->grand_total,
                    'weight' => 15000,
                    'quantity' => 1,
                ]
            ],
        ];

        try {
            $biteshipResult = $this->biteshipClient->createOrder($orderPayload);
        } catch (Exception $e) {
            // 5. AUTOMATIC ROLLBACK / REFUND OF DEPOSIT WHEN BITESHIP FAILS
            Log::error("Biteship createOrder failed for order #{$order->id}. Executing auto-rollback refund.", [
                'error' => $e->getMessage(),
            ]);

            $refundKey = "refund:courier:{$order->id}:" . time();
            $this->depositLedgerService->credit(
                wallet: $wallet->fresh(),
                amount: $actualFee,
                type: 'refund',
                idempotencyKey: $refundKey,
                orderId: $order->id,
                note: "Kompensasi refund ongkir kurir gagal dipanggil pada order #{$order->order_code}"
            );

            throw new CourierApiException('Pemanggilan kurir partner gagal: ' . $e->getMessage(), 502);
        }

        // 6. Persist Shipment and transition order state
        $totalWeight = $this->vehicleSelector->calculateOrderWeight($order);
        $vehicleClass = $this->vehicleSelector->selectVehicle($totalWeight)['vehicle_class'];

        $shipment = Shipment::updateOrCreate(
            ['order_id' => $order->id],
            [
                'vehicle_class' => $vehicleClass,
                'courier_company' => $courierCompany,
                'courier_type' => $courierType,
                'biteship_order_id' => $biteshipResult['biteship_order_id'],
                'waybill_id' => $biteshipResult['waybill_id'],
                'tracking_url' => $biteshipResult['tracking_url'],
                'shipping_fee_actual' => $actualFee,
                'status' => $biteshipResult['status'] ?? 'allocated',
                'driver_name' => $biteshipResult['driver_name'] ?? 'Driver Assigned',
                'driver_phone' => $biteshipResult['driver_phone'] ?? '08123456789',
                'vehicle_number' => $biteshipResult['vehicle_number'] ?? 'B 1234 BS',
                'raw_response' => $biteshipResult['raw_response'] ?? [],
            ]
        );

        $this->orderStateMachine->transition(
            $order,
            OrderStateMachine::STATE_COURIER_REQUESTED,
            null,
            "Kurir {$courierCompany} berhasil dipanggil dengan no resi {$biteshipResult['waybill_id']}"
        );

        // 7. Dispatch COURIER_DISPATCHED Notification
        $this->notificationService->notifyCourierDispatched(
            order: $order,
            merchantUserId: $order->merchant_id,
            courierName: ucfirst($courierCompany) . " ({$shipment->waybill_id})",
            trackingUrl: $shipment->tracking_url ?? "https://biteship.com/track/{$shipment->waybill_id}"
        );

        $walletAfter = DepositWallet::where('supplier_id', $order->supplier_id)->first();

        return [
            'order_id' => $order->id,
            'order_code' => $order->order_code,
            'status' => $order->status,
            'shipment' => $shipment,
            'wallet' => [
                'balance_before' => $balanceBefore,
                'amount_deducted' => $actualFee,
                'balance_after' => $walletAfter ? $walletAfter->balance : 0,
            ],
        ];
    }

    /**
     * Handover physical items directly (self_pickup / manual dispatch).
     */
    public function handover(Order $order, string $notes = '', ?string $photoPath = null): Order
    {
        // Must be in valid state (PAID_FULL or READY_FOR_PICKUP)
        if (!in_array($order->status, [OrderStateMachine::STATE_PAID_FULL, OrderStateMachine::STATE_READY_FOR_PICKUP])) {
            throw new InvalidOrderStateException("Handover hanya dapat dilakukan pada status PAID_FULL atau READY_FOR_PICKUP", 409);
        }

        $shipment = Shipment::updateOrCreate(
            ['order_id' => $order->id],
            [
                'status' => 'delivered',
                'delivered_at' => now(),
                'handover_notes' => $notes,
                'handover_photo_path' => $photoPath,
            ]
        );

        if ($order->status === OrderStateMachine::STATE_PAID_FULL) {
            $this->orderStateMachine->transition($order, OrderStateMachine::STATE_READY_FOR_PICKUP, null, 'Siap diserahterimakan');
        }

        $this->orderStateMachine->transition($order, OrderStateMachine::STATE_COMPLETED, null, 'Pesanan diserahterimakan (Handover)');

        // Saga stock consume
        $this->consumeStockSaga($order->id);

        // Dispatch notifications
        $this->notificationService->notifyOrderCompleted($order, $order->merchant_id);
        $this->notificationService->notifyOrderCompleted($order, $order->supplier_id);

        return $order->fresh(['shipment']);
    }

    /**
     * Process incoming webhook callback from Biteship.
     */
    public function handleWebhook(array $payload): array
    {
        $eventId = $payload['event_id'] ?? null;
        if ($eventId) {
            $cacheKey = "biteship_event:{$eventId}";
            if (Cache::has($cacheKey)) {
                return ['acknowledged' => true, 'duplicate' => true];
            }
            Cache::put($cacheKey, true, now()->addHours(24));
        }

        $biteshipOrderId = $payload['order_id'] ?? null;
        $trackingId = $payload['courier_tracking_id'] ?? null;
        $status = strtolower($payload['status'] ?? '');

        $shipment = Shipment::where('biteship_order_id', $biteshipOrderId)
            ->orWhere('biteship_tracking_id', $trackingId)
            ->orWhere('waybill_id', $trackingId)
            ->first();

        if (!$shipment) {
            Log::warning("Biteship webhook received for unknown order: {$biteshipOrderId}");
            return ['acknowledged' => true, 'matched' => false];
        }

        $order = $shipment->order;
        if (!$order) {
            return ['acknowledged' => true, 'matched' => false];
        }

        // Update driver details if provided
        $shipment->update([
            'driver_name' => $payload['driver_name'] ?? $shipment->driver_name,
            'driver_phone' => $payload['driver_phone'] ?? $shipment->driver_phone,
            'vehicle_number' => $payload['vehicle_number'] ?? $shipment->vehicle_number,
            'status' => $status,
            'raw_response' => array_merge((array) ($shipment->raw_response ?? []), ['latest_webhook' => $payload]),
        ]);

        if (in_array($status, ['picked', 'picking_up', 'dropping_off']) && $order->status === OrderStateMachine::STATE_COURIER_REQUESTED) {
            $this->orderStateMachine->transition($order, OrderStateMachine::STATE_IN_DELIVERY, null, "Kurir status: {$status}");
        } elseif ($status === 'delivered' && in_array($order->status, [OrderStateMachine::STATE_COURIER_REQUESTED, OrderStateMachine::STATE_IN_DELIVERY])) {
            $shipment->update(['delivered_at' => now()]);
            $this->orderStateMachine->transition($order, OrderStateMachine::STATE_COMPLETED, null, "Kurir berhasil mengantar barang (Delivered)");

            // Consume stock Saga
            $this->consumeStockSaga($order->id);

            // Dispatch ORDER_COMPLETED notifications
            $this->notificationService->notifyOrderCompleted($order, $order->merchant_id);
            $this->notificationService->notifyOrderCompleted($order, $order->supplier_id);
        }

        return ['acknowledged' => true, 'order_status' => $order->status];
    }

    protected function consumeStockSaga(int $orderId): void
    {
        try {
            $catalogUrl = env('SVC_CATALOG_URL', 'http://svc-catalog:8000');
            Http::timeout(3)->post("{$catalogUrl}/api/v1/internal/saga/consume-stock", [
                'order_id' => $orderId,
            ]);
        } catch (Exception $e) {
            Log::warning("Saga consume-stock call failed for order {$orderId}: " . $e->getMessage());
        }
    }
}
