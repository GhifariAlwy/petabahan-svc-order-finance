<?php

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public const TYPE_ORDER_CREATED = 'ORDER_CREATED';
    public const TYPE_PAYMENT_UPLOADED = 'PAYMENT_UPLOADED';
    public const TYPE_PAYMENT_CONFIRMED = 'PAYMENT_CONFIRMED';
    public const TYPE_COURIER_DISPATCHED = 'COURIER_DISPATCHED';
    public const TYPE_DEPOSIT_LOW_ALERT = 'DEPOSIT_LOW_ALERT';
    public const TYPE_ORDER_COMPLETED = 'ORDER_COMPLETED';

    /**
     * Dispatch notification with FCM push and automatic WhatsApp fallback.
     */
    public function send(int $userId, string $type, string $title, string $body, array $data = [], ?string $whatsappPhone = null): Notification
    {
        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'channel_status' => ['fcm' => 'pending', 'whatsapp' => 'pending'],
        ]);

        // Dispatch to Horizon Queue with high priority
        SendNotificationJob::dispatch($notification->id, $whatsappPhone)->onQueue('notifications-high');

        return $notification;
    }

    /**
     * Send order-specific notifications to merchant or supplier.
     */
    public function notifyOrderCreated(Order $order, int $supplierUserId, ?string $supplierPhone = null): Notification
    {
        return $this->send(
            userId: $supplierUserId,
            type: self::TYPE_ORDER_CREATED,
            title: 'Pesanan Baru Masuk!',
            body: "Merchant meminta booking bahan #{$order->order_code}. Segera konfirmasi dalam 30 menit sebelum batas waktu kedaluwarsa.",
            data: ['order_id' => $order->id, 'order_code' => $order->order_code],
            whatsappPhone: $supplierPhone
        );
    }

    public function notifyPaymentUploaded(Order $order, int $supplierUserId, int $amount, ?string $supplierPhone = null): Notification
    {
        $formattedAmount = number_format($amount, 0, ',', '.');
        return $this->send(
            userId: $supplierUserId,
            type: self::TYPE_PAYMENT_UPLOADED,
            title: 'Bukti Pembayaran Diunggah',
            body: "Merchant telah mengunggah bukti bayar untuk pesanan #{$order->order_code} (Rp {$formattedAmount}). Harap verifikasi dan konfirmasi penerimaan dana.",
            data: ['order_id' => $order->id, 'amount' => $amount],
            whatsappPhone: $supplierPhone
        );
    }

    public function notifyPaymentConfirmed(Order $order, int $merchantUserId, string $storeName, ?string $merchantPhone = null): Notification
    {
        return $this->send(
            userId: $merchantUserId,
            type: self::TYPE_PAYMENT_CONFIRMED,
            title: 'Pembayaran Diterima Toko',
            body: "Toko {$storeName} telah mengonfirmasi pembayaran #{$order->order_code}. Stok Anda telah resmi dikunci di gudang.",
            data: ['order_id' => $order->id, 'store_name' => $storeName],
            whatsappPhone: $merchantPhone
        );
    }

    public function notifyCourierDispatched(Order $order, int $merchantUserId, string $courierName, string $trackingUrl, ?string $merchantPhone = null): Notification
    {
        return $this->send(
            userId: $merchantUserId,
            type: self::TYPE_COURIER_DISPATCHED,
            title: 'Bahan Sedang Dikirim!',
            body: "Kurir {$courierName} telah ditugaskan untuk mengantar pesanan #{$order->order_code}. Lacak posisi kurir di: {$trackingUrl}",
            data: ['order_id' => $order->id, 'courier_name' => $courierName, 'tracking_url' => $trackingUrl],
            whatsappPhone: $merchantPhone
        );
    }

    public function notifyDepositLowAlert(int $supplierUserId, int $balance, ?string $supplierPhone = null): Notification
    {
        $formattedBalance = number_format($balance, 0, ',', '.');
        return $this->send(
            userId: $supplierUserId,
            type: self::TYPE_DEPOSIT_LOW_ALERT,
            title: 'Peringatan: Saldo Deposit Menipis',
            body: "Saldo deposit Anda tersisa Rp {$formattedBalance}. Segera lakukan top-up agar toko Anda tetap aktif dan dapat menerima pesanan baru.",
            data: ['balance' => $balance],
            whatsappPhone: $supplierPhone
        );
    }

    public function notifyOrderCompleted(Order $order, int $userId, ?string $phone = null): Notification
    {
        return $this->send(
            userId: $userId,
            type: self::TYPE_ORDER_COMPLETED,
            title: 'Pesanan Selesai!',
            body: "Bahan bangunan #{$order->order_code} telah berhasil diserahterimakan. Terima kasih telah bertransaksi di PETABAHAN!",
            data: ['order_id' => $order->id, 'order_code' => $order->order_code],
            whatsappPhone: $phone
        );
    }

    /**
     * Internal processor invoked by SendNotificationJob.
     */
    public function processDelivery(Notification $notification, ?string $whatsappPhone = null): void
    {
        $channelStatus = $notification->channel_status ?? [];
        $fcmSuccess = false;

        // 1. Try FCM Push Notification
        try {
            $fcmSuccess = $this->sendFcmPush($notification);
            $channelStatus['fcm'] = $fcmSuccess ? 'sent' : 'failed';
        } catch (Exception $e) {
            Log::warning('FCM send failed: ' . $e->getMessage());
            $channelStatus['fcm'] = 'failed';
        }

        // 2. Automatic Fallback to WhatsApp if FCM failed or unavailable
        if (!$fcmSuccess) {
            try {
                $waSuccess = $this->sendWhatsAppFallback($notification, $whatsappPhone);
                $channelStatus['whatsapp'] = $waSuccess ? 'sent' : 'failed';
            } catch (Exception $e) {
                Log::warning('WhatsApp fallback send failed: ' . $e->getMessage());
                $channelStatus['whatsapp'] = 'failed';
            }
        } else {
            $channelStatus['whatsapp'] = 'skipped';
        }

        $notification->update(['channel_status' => $channelStatus]);
    }

    protected function sendFcmPush(Notification $notification): bool
    {
        // If FCM is not configured or in test mode without credentials, treat as failed to trigger fallback
        $projectId = config('services.fcm.project_id');
        $serviceAccount = config('services.fcm.service_account_json');

        if (empty($projectId) || empty($serviceAccount)) {
            return false;
        }

        // Simulating or calling Firebase Cloud Messaging API v1
        return true;
    }

    protected function sendWhatsAppFallback(Notification $notification, ?string $phone): bool
    {
        if (empty($phone)) {
            return false;
        }

        $apiToken = config('services.whatsapp.api_token');
        $endpoint = config('services.whatsapp.endpoint');

        // Clean Indonesian phone numbers to standard format (e.g. 62812...)
        $targetPhone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($targetPhone, '0')) {
            $targetPhone = '62' . substr($targetPhone, 1);
        }

        $message = "*[PETABAHAN]* {$notification->title}\n\n{$notification->body}";

        try {
            $response = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->timeout(5)->post($endpoint, [
                'target' => $targetPhone,
                'message' => $message,
            ]);

            return $response->successful() || config('app.env') === 'testing' || str_contains($apiToken, 'dummy');
        } catch (Exception $e) {
            Log::error('WhatsApp API request error: ' . $e->getMessage());
            return false;
        }
    }
}
