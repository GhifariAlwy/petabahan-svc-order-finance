<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class PaymentInsufficientException extends Exception
{
    public function __construct(string $message = 'Payment amount is insufficient for full payment scheme', int $code = 422)
    {
        parent::__construct($message, $code);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'PAYMENT_INSUFFICIENT',
                'message' => $this->getMessage(),
            ]
        ], 422);
    }
}
