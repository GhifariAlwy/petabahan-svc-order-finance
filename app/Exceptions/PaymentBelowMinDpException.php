<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class PaymentBelowMinDpException extends Exception
{
    public function __construct(string $message = 'Payment amount is below minimum required down payment (50% materials + platform fee)', int $code = 422)
    {
        parent::__construct($message, $code);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'PAYMENT_BELOW_MIN_DP',
                'message' => $this->getMessage(),
            ]
        ], 422);
    }
}
