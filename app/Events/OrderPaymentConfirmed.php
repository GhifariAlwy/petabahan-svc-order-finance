<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderPaymentConfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $orderId,
        public int $supplierId
    ) {}
}
