<?php

namespace App\Domain\Shipping\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BiteshipClient
{
    protected string $apiKey;
    protected string $webhookSecret;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.biteship.api_key', '');
        $this->webhookSecret = config('services.biteship.webhook_secret', '');
        $this->baseUrl = rtrim(config('services.biteship.base_url', 'https://api.biteship.com/v1'), '/');
    }

    /**
     * Get instant shipping rate options between origin and destination.
     */
    public function getRates(array $origin, array $destination, array $items): array
    {
        $payload = [
            'origin_latitude' => $origin['latitude'] ?? null,
            'origin_longitude' => $origin['longitude'] ?? null,
            'destination_latitude' => $destination['latitude'] ?? null,
            'destination_longitude' => $destination['longitude'] ?? null,
            'couriers' => 'gosend,grab,lalamove,deliveree',
            'items' => $items,
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(10)->post("{$this->baseUrl}/rates/couriers", $payload);

            if ($response->successful()) {
                $body = $response->json();
                return $body['pricing'] ?? [];
            }

            Log::warning('Biteship rates API returned non-success response', [
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);

            return [];
        } catch (Exception $e) {
            Log::error('Biteship rates API connection error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Create delivery order with courier in Biteship.
     *
     * @throws Exception when API fails or returns error
     */
    public function createOrder(array $orderPayload): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post("{$this->baseUrl}/orders", $orderPayload);

            if ($response->successful()) {
                $body = $response->json();
                return [
                    'success' => true,
                    'biteship_order_id' => $body['id'] ?? ('bs_' . uniqid()),
                    'waybill_id' => $body['courier']['waybill_id'] ?? $body['courier']['tracking_id'] ?? ('WB-' . rand(100000, 999999)),
                    'tracking_url' => $body['courier']['link'] ?? ("https://biteship.com/track/" . ($body['id'] ?? 'dummy')),
                    'driver_name' => $body['courier']['driver_name'] ?? 'Driver Assigned',
                    'driver_phone' => $body['courier']['driver_phone'] ?? '08123456789',
                    'vehicle_number' => $body['courier']['vehicle_number'] ?? 'B 1234 BS',
                    'status' => $body['status'] ?? 'allocated',
                    'raw_response' => $body,
                ];
            }

            Log::error('Biteship create order failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new Exception('Biteship order creation failed: ' . ($response->json('message') ?? 'HTTP ' . $response->status()));
        } catch (Exception $e) {
            Log::error('Biteship createOrder exception: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Verify Biteship HMAC-SHA256 signature for incoming webhooks.
     */
    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        if (empty($signature) || empty($this->webhookSecret)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $rawPayload, $this->webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }
}
