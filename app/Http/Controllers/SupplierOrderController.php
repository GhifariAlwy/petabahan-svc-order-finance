<?php

namespace App\Http\Controllers;

use App\Domain\Order\Services\BookingService;
use App\Domain\Order\Services\PaymentService;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupplierOrderController extends Controller
{
    public function __construct(
        protected BookingService $bookingService,
        protected PaymentService $paymentService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $supplierId = (int) ($request->header('X-Supplier-Id') ?? $request->query('supplier_id', 1));

        $query = Order::where('supplier_id', $supplierId)->with(['items', 'payments', 'shipment']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $orders = $query->orderByDesc('id')->paginate(15);

        return response()->json([
            'status' => 'ok',
            'data' => $orders,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $supplierId = (int) ($request->header('X-Supplier-Id') ?? $request->query('supplier_id', 1));
        $order = Order::with(['items', 'payments', 'shipment', 'statusLogs'])->findOrFail($id);

        if ($order->supplier_id !== $supplierId && $request->header('X-User-Role') !== 'admin') {
            return response()->json(['error' => ['code' => 'FORBIDDEN_OWNERSHIP', 'message' => 'Pesanan bukan milik Anda']], 403);
        }

        return response()->json([
            'status' => 'ok',
            'data' => $order,
        ]);
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        $supplierId = (int) ($request->header('X-Supplier-Id') ?? $request->input('supplier_id', 1));
        $order = Order::findOrFail($id);

        if ($order->supplier_id !== $supplierId && $request->header('X-User-Role') !== 'admin') {
            return response()->json(['error' => ['code' => 'FORBIDDEN_OWNERSHIP', 'message' => 'Pesanan bukan milik Anda']], 403);
        }

        $unavailableItemIds = $request->input('unavailable_item_ids', []);

        $accepted = $this->bookingService->acceptBooking($order, $unavailableItemIds, $supplierId);

        return response()->json([
            'status' => 'ok',
            'message' => 'Booking berhasil dikonfirmasi oleh Agen.',
            'data' => $accepted,
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $supplierId = (int) ($request->header('X-Supplier-Id') ?? $request->input('supplier_id', 1));
        $order = Order::findOrFail($id);

        if ($order->supplier_id !== $supplierId && $request->header('X-User-Role') !== 'admin') {
            return response()->json(['error' => ['code' => 'FORBIDDEN_OWNERSHIP', 'message' => 'Pesanan bukan milik Anda']], 403);
        }

        $reason = $request->input('reason', 'Stok tidak mencukupi / toko tutup');

        $rejected = $this->bookingService->rejectBooking($order, $reason, $supplierId);

        return response()->json([
            'status' => 'ok',
            'message' => 'Booking telah ditolak.',
            'data' => $rejected,
        ]);
    }

    public function confirmPayment(Request $request, int $id, int $pid): JsonResponse
    {
        $supplierId = (int) ($request->header('X-Supplier-Id') ?? $request->input('supplier_id', 1));
        $order = Order::findOrFail($id);

        if ($order->supplier_id !== $supplierId && $request->header('X-User-Role') !== 'admin') {
            return response()->json(['error' => ['code' => 'FORBIDDEN_OWNERSHIP', 'message' => 'Pesanan bukan milik Anda']], 403);
        }

        $payment = OrderPayment::where('order_id', $order->id)->findOrFail($pid);

        if ($payment->type === 'first') {
            $confirmed = $this->paymentService->confirmFirstPayment($order->id, $payment->id, $supplierId);
        } else {
            $confirmed = $this->paymentService->confirmSettlement($order->id, $payment->id, $supplierId);
        }

        return response()->json([
            'status' => 'ok',
            'message' => 'Pembayaran berhasil dikonfirmasi. Status order diperbarui.',
            'data' => $confirmed,
        ]);
    }

    public function rejectPayment(Request $request, int $id, int $pid): JsonResponse
    {
        $supplierId = (int) ($request->header('X-Supplier-Id') ?? $request->input('supplier_id', 1));
        $order = Order::findOrFail($id);

        if ($order->supplier_id !== $supplierId && $request->header('X-User-Role') !== 'admin') {
            return response()->json(['error' => ['code' => 'FORBIDDEN_OWNERSHIP', 'message' => 'Pesanan bukan milik Anda']], 403);
        }

        $reason = $request->input('reject_reason', 'Bukti transfer tidak valid atau dana belum masuk rekening');

        $rejected = $this->paymentService->rejectPayment($order->id, $pid, $reason, $supplierId);

        return response()->json([
            'status' => 'ok',
            'message' => 'Bukti pembayaran ditolak.',
            'data' => $rejected,
        ]);
    }

    public function proofSignedUrl(Request $request, int $id, int $pid): JsonResponse
    {
        $supplierId = (int) ($request->header('X-Supplier-Id') ?? $request->query('supplier_id', 1));
        $order = Order::findOrFail($id);

        if ($order->supplier_id !== $supplierId && $request->header('X-User-Role') !== 'admin') {
            return response()->json(['error' => ['code' => 'FORBIDDEN_OWNERSHIP', 'message' => 'Pesanan bukan milik Anda']], 403);
        }

        $payment = OrderPayment::where('order_id', $order->id)->findOrFail($pid);
        $signedUrl = $this->paymentService->getSignedProofUrl($payment, 10);

        return response()->json([
            'status' => 'ok',
            'data' => [
                'signed_url' => $signedUrl,
                'expires_in_minutes' => 10,
            ],
        ]);
    }

    public function streamProof(Request $request, int $id, int $pid): BinaryFileResponse|JsonResponse
    {
        if (!$request->hasValidSignature()) {
            return response()->json(['error' => ['code' => 'INVALID_SIGNATURE', 'message' => 'Link kedaluwarsa atau signature tidak valid']], 403);
        }

        $payment = OrderPayment::where('order_id', $id)->findOrFail($pid);
        $disk = Storage::disk('private');

        if (!$disk->exists($payment->proof_path)) {
            return response()->json(['error' => ['code' => 'FILE_NOT_FOUND', 'message' => 'File bukti transfer tidak ditemukan']], 404);
        }

        return response()->file($disk->path($payment->proof_path));
    }
}
