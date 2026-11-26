<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockLockFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $orderId,
        public string $reason
    ) {}
}
