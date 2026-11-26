<?php

namespace App\Domain\Notification\Jobs;

use App\Domain\Notification\Services\NotificationService;
use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(
        public int $notificationId,
        public ?string $whatsappPhone = null
    ) {}

    public function handle(NotificationService $notificationService): void
    {
        $notification = Notification::find($this->notificationId);
        if (!$notification) {
            return;
        }

        $notificationService->processDelivery($notification, $this->whatsappPhone);
    }
}
