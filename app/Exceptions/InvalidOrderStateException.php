<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class InvalidOrderStateException extends Exception
{
    public function __construct(string $message = 'Invalid order state transition', int $code = 409)
    {
        parent::__construct($message, $code);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'INVALID_ORDER_STATE',
                'message' => $this->getMessage(),
            ]
        ], 409);
    }
}
