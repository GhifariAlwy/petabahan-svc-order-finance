<?php

use App\Domain\Order\Services\OrderExpirationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('petabahan:expire-orders')->everyMinute();
Schedule::command('petabahan:reconcile')->dailyAt('00:00');
