<?php

namespace App\Console\Commands;

use App\Domain\Order\Services\OrderExpirationService;
use Illuminate\Console\Command;

class ExpireOrdersCommand extends Command
{
    protected $signature = 'petabahan:expire-orders';

    protected $description = 'Periksa dan kedaluwarsakan pesanan sesuai batas SLA & waktu bayar';

    public function handle(OrderExpirationService $expirationService): int
    {
        $results = $expirationService->runAll();

        $this->info("Booking kedaluwarsa (SLA 30m): {$results['expired_bookings']}");
        $this->info("Order belum bayar kedaluwarsa (2 jam): {$results['expired_unpaid']}");
        $this->info("Order DP belum lunas dibatalkan (24 jam): {$results['cancelled_unsettled_dp']}");

        return 0;
    }
}
