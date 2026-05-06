<?php

namespace App\Listeners;

use App\Events\DealStatusChanged;
use App\Notifications\DealStatusChangedNotification;
use App\Services\TwilioService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendDealStatusNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly TwilioService $twilio,
    ) {}

    public function handle(DealStatusChanged $event): void
    {
        $buyer = $event->deal->buyer;

        if (! $buyer) {
            return;
        }

        // Email notification
        try {
            $buyer->notify(new DealStatusChangedNotification(
                $event->deal,
                $event->oldStatus,
                $event->newStatus,
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send deal status email', [
                'deal_id' => $event->deal->id,
                'error'   => $e->getMessage(),
            ]);
        }

        // SMS notification (optional — only when phone is set)
        if ($buyer->phone) {
            try {
                $statusLabel = str_replace('_', ' ', ucfirst($event->newStatus));
                $this->twilio->sendSms(
                    $buyer->phone,
                    "AutoClose: Your deal #{$event->deal->id} status changed to \"{$statusLabel}\". Check your email for details."
                );
            } catch (\Throwable $e) {
                Log::error('Failed to send deal status SMS', [
                    'deal_id' => $event->deal->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
}
