<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class InsufficientDepositException extends Exception
{
    public function __construct(string $message = 'Insufficient deposit balance to confirm order', int $code = 422)
    {
        parent::__construct($message, $code);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'INSUFFICIENT_DEPOSIT',
                'message' => $this->getMessage(),
            ]
        ], 422);
    }
}
