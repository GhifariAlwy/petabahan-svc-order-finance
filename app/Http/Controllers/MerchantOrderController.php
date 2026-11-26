<?php

namespace App\Http\Controllers;

use App\Domain\Order\Services\BookingService;
use App\Domain\Order\Services\OrderStateMachine;
use App\Domain\Order\Services\PaymentService;
use App\Models\Order;
use App\Models\StockReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantOrderController extends Controller
{
    public function __construct(
        protected BookingService $bookingService,
        protected PaymentService $paymentService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $merchantId = (int) ($request->header('X-Merchant-Id') ?? $request->query('merchant_id', 1));

        $query = Order::where('merchant_id', $merchantId)->with(['items', 'payments', 'shipment']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $orders = $query->orderByDesc('id')->paginate(15);

        return response()->json([
            'status' => 'ok',
            'data' => $orders,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $merchantId = (int) ($request->header('X-Merchant-Id') ?? $request->input('merchant_id', 1));

        $validated = $request->validate([
            'supplier_id' => 'required|integer',
            'shopping_list_id' => 'nullable|integer',
            'payment_scheme' => 'nullable|string|in:dp,full',
            'fulfillment_type' => 'nullable|string|in:pickup,courier',
            'shipping_cost_estimate' => 'nullable|integer|min:0',
            'total_weight_kg' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.material_name' => 'required|string',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.unit' => 'required|string',
            'items.*.unit_price' => 'required|integer|min:0',
            'items.*.tier_id' => 'nullable|integer',
        ]);

        $order = $this->bookingService->createBooking($validated, $merchantId);

        return response()->json([
            'status' => 'ok',
            'message' => 'Booking berhasil diajukan tanpa uang di muka (BR-1). Menunggu konfirmasi Agen.',
            'data' => $order,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $merchantId = (int) ($request->header('X-Merchant-Id') ?? $request->query('merchant_id', 1));
        $order = Order::with(['items', 'payments', 'shipment', 'statusLogs'])->findOrFail($id);

        if ($order->merchant_id !== $merchantId && $request->header('X-User-Role') !== 'admin') {
            return response()->json(['error' => ['code' => 'FORBIDDEN_OWNERSHIP', 'message' => 'Pesanan bukan milik Anda']], 403);
        }

        return response()->json([
            'status' => 'ok',
            'data' => $order,
        ]);
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $merchantId = (int) ($request->header('X-Merchant-Id') ?? $request->input('merchant_id', 1));
        $order = Order::findOrFail($id);

        if ($order->merchant_id !== $merchantId && $request->header('X-User-Role') !== 'admin') {
            return response()->json(['error' => ['code' => 'FORBIDDEN_OWNERSHIP', 'message' => 'Pesanan bukan milik Anda']], 403);
        }

        $validated = $request->validate([
            'amount' => 'required|integer|min:1',
            'payment_scheme' => 'nullable|string|in:dp,full',
            'fulfillment_type' => 'nullable|string|in:pickup,courier',
            'proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $payment = $this->paymentService->uploadFirstPayment(
            $order,
            (int) $validated['amount'],
            $request->file('proof'),
            $validated['payment_scheme'] ?? null,
            $validated['fulfillment_type'] ?? null,
            $merchantId
        );

        return response()->json([
            'status' => 'ok',
            'message' => 'Bukti pembayaran pertama berhasil diunggah. Menunggu verifikasi Agen.',
            'data' => $payment,
        ]);
    }

    public function settle(Request $request, int $id): JsonResponse
    {
        $merchantId = (int) ($request->header('X-Merchant-Id') ?? $request->input('merchant_id', 1));
        $order = Order::findOrFail($id);

        if ($order->merchant_id !== $merchantId && $request->header('X-User-Role') !== 'admin') {
            return response()->json(['error' => ['code' => 'FORBIDDEN_OWNERSHIP', 'message' => 'Pesanan bukan milik Anda']], 403);
        }

        $validated = $request->validate([
            'amount' => 'required|integer|min:1',
            'proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $payment = $this->paymentService->uploadSettlement(
            $order,
            (int) $validated['amount'],
            $request->file('proof'),
            $merchantId
        );

        return response()->json([
            'status' => 'ok',
            'message' => 'Bukti pelunasan berhasil diunggah. Menunggu konfirmasi Agen.',
            'data' => $payment,
        ]);
    }

    public function updateFulfillment(Request $request, int $id): JsonResponse
    {
        $merchantId = (int) ($request->header('X-Merchant-Id') ?? $request->input('merchant_id', 1));
        $order = Order::findOrFail($id);

        if ($order->merchant_id !== $merchantId && $request->header('X-User-Role') !== 'admin') {
            return response()->json(['error' => ['code' => 'FORBIDDEN_OWNERSHIP', 'message' => 'Pesanan bukan milik Anda']], 403);
        }

        $validated = $request->validate([
            'fulfillment_type' => 'required|string|in:pickup,courier',
            'shipping_cost_estimate' => 'nullable|integer|min:0',
        ]);

        $order->fulfillment_type = $validated['fulfillment_type'];
        if (isset($validated['shipping_cost_estimate'])) {
            $order->shipping_cost_estimate = $validated['shipping_cost_estimate'];
            $order->grand_total_merchant = $order->subtotal_materials + $order->merchant_fee + $order->shipping_cost_estimate;
        }
        $order->save();

        return response()->json([
            'status' => 'ok',
            'message' => 'Metode pemenuhan berhasil diperbarui.',
            'data' => $order,
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $merchantId = (int) ($request->header('X-Merchant-Id') ?? $request->input('merchant_id', 1));
        $order = Order::findOrFail($id);

        if ($order->merchant_id !== $merchantId && $request->header('X-User-Role') !== 'admin') {
            return response()->json(['error' => ['code' => 'FORBIDDEN_OWNERSHIP', 'message' => 'Pesanan bukan milik Anda']], 403);
        }

        $reason = $request->input('reason', 'Dibatalkan oleh Merchant');

        OrderStateMachine::transition(
            $order,
            OrderStateMachine::STATE_CANCELLED,
            $merchantId,
            $reason
        );

        return response()->json([
            'status' => 'ok',
            'message' => 'Pesanan berhasil dibatalkan.',
            'data' => $order,
        ]);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $merchantId = (int) ($request->header('X-Merchant-Id') ?? $request->input('merchant_id', 1));
        $order = Order::findOrFail($id);

        if ($order->merchant_id !== $merchantId && $request->header('X-User-Role') !== 'admin') {
            return response()->json(['error' => ['code' => 'FORBIDDEN_OWNERSHIP', 'message' => 'Pesanan bukan milik Anda']], 403);
        }

        OrderStateMachine::transition(
            $order,
            OrderStateMachine::STATE_COMPLETED,
            $merchantId,
            'Pesanan diselesaikan: barang telah diterima oleh Merchant.'
        );

        // Mark reservations consumed
        StockReservation::where('order_id', $order->id)->update(['status' => 'consumed']);

        return response()->json([
            'status' => 'ok',
            'message' => 'Pesanan selesai.',
            'data' => $order,
        ]);
    }
}
