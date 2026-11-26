<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shipping\Services\ShippingService;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingController extends Controller
{
    public function __construct(
        protected ShippingService $shippingService
    ) {}

    public function setFulfillment(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'fulfillment_type' => 'required|in:courier,self_pickup',
            'destination_address' => 'nullable|string',
            'destination_latitude' => 'nullable|numeric',
            'destination_longitude' => 'nullable|numeric',
            'recipient_name' => 'nullable|string',
            'recipient_phone' => 'nullable|string',
            'delivery_notes' => 'nullable|string',
        ]);

        $order = Order::with('items')->findOrFail($id);

        $updated = $this->shippingService->setFulfillment(
            $order,
            $validated['fulfillment_type'],
            $validated
        );

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $updated->id,
                'order_code' => $updated->order_code,
                'fulfillment_type' => $updated->fulfillment_type,
                'status' => $updated->status,
                'shipment' => $updated->shipment,
            ],
        ]);
    }

    public function getShippingQuote(int $id): JsonResponse
    {
        $order = Order::with('items')->findOrFail($id);

        $quote = $this->shippingService->getShippingQuote($order);

        return response()->json([
            'success' => true,
            'data' => $quote,
        ]);
    }

    public function callCourier(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'courier_company' => 'nullable|string',
            'courier_type' => 'nullable|string',
            'shipping_fee_actual' => 'nullable|integer',
            'notes_for_driver' => 'nullable|string',
        ]);

        $order = Order::with('items')->findOrFail($id);

        $result = $this->shippingService->callCourier($order, $validated);

        return response()->json([
            'success' => true,
            'data' => $result,
        ], 201);
    }

    public function handover(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string',
            'proof_photo' => 'nullable|image|max:5120',
        ]);

        $order = Order::findOrFail($id);

        $photoPath = null;
        if ($request->hasFile('proof_photo')) {
            $photoPath = $request->file('proof_photo')->store('handovers', 'local');
        }

        $completed = $this->shippingService->handover($order, $validated['notes'] ?? '', $photoPath);

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $completed->id,
                'order_code' => $completed->order_code,
                'status' => $completed->status,
                'delivered_at' => $completed->shipment?->delivered_at,
            ],
        ]);
    }
}
