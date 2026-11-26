<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class CourierApiException extends Exception
{
    public function __construct(string $message = 'Courier partner API communication failed', int $code = 502)
    {
        parent::__construct($message, $code);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'COURIER_API_FAILED',
                'message' => $this->getMessage(),
            ]
        ], 502);
    }
}
