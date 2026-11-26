<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shipping\Services\BiteshipClient;
use App\Domain\Shipping\Services\ShippingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BiteshipWebhookController extends Controller
{
    public function __construct(
        protected BiteshipClient $biteshipClient,
        protected ShippingService $shippingService
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $rawContent = $request->getContent();
        $signature = $request->header('X-Biteship-Signature');

        // Verify HMAC-SHA256 signature
        $secret = config('services.biteship.webhook_secret');
        $enforceHmac = config('services.biteship.enforce_webhook_hmac', !app()->environment('testing'));

        if (!empty($secret)) {
            if (!empty($signature)) {
                if (!$this->biteshipClient->verifyWebhookSignature($rawContent, $signature)) {
                    Log::warning('Unauthorized Biteship webhook signature attempt', [
                        'ip' => $request->ip(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'INVALID_SIGNATURE',
                            'message' => 'Tanda tangan webhook HMAC tidak valid atau tidak cocok.',
                        ],
                    ], 403);
                }
            } elseif ($enforceHmac) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_SIGNATURE',
                        'message' => 'Tanda tangan webhook HMAC (X-Biteship-Signature) wajib disertakan.',
                    ],
                ], 403);
            }
        }

        $payload = $request->all();
        $result = $this->shippingService->handleWebhook($payload);

        return response()->json([
            'success' => true,
            'message' => 'Webhook received and processed',
            'data' => $result,
        ]);
    }
}
