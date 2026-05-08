<?php

namespace App\Listeners;

use App\Events\DealStatusChanged;
use App\Notifications\PostPurchaseDeliveredNotification;
use App\Notifications\PostPurchaseDeliveryReadyNotification;
use App\Notifications\PostPurchaseDocsSignedNotification;
use App\Services\TwilioService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Triggers the post-purchase SMS/email journey at three key deal milestones:
 *
 *   docs_signed        → paperwork confirmation email + SMS
 *   awaiting_delivery  → vehicle-ready email + SMS
 *   delivered          → welcome-to-the-family email + review-request SMS
 */
class TriggerPostPurchaseJourney implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly TwilioService $twilio,
    ) {}

    public function handle(DealStatusChanged $event): void
    {
        if (! in_array($event->newStatus, ['docs_signed', 'awaiting_delivery', 'delivered'], true)) {
            return;
        }

        $buyer = $event->deal->buyer;

        if (! $buyer) {
            return;
        }

        match ($event->newStatus) {
            'docs_signed'       => $this->handleDocsSigned($event, $buyer),
            'awaiting_delivery' => $this->handleDeliveryReady($event, $buyer),
            'delivered'         => $this->handleDelivered($event, $buyer),
        };
    }

    private function handleDocsSigned(DealStatusChanged $event, \App\Models\User $buyer): void
    {
        $deal = $event->deal;

        $this->sendEmail($buyer, new PostPurchaseDocsSignedNotification($deal));

        if ($buyer->phone) {
            $vehicle = $deal->vehicle;
            $vehicleLabel = $vehicle ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}" : 'your vehicle';

            $this->sendSms(
                $buyer->phone,
                "AutoClose: 🎉 Your paperwork for {$vehicleLabel} is signed! We'll be in touch soon to arrange delivery.",
            );
        }
    }

    private function handleDeliveryReady(DealStatusChanged $event, \App\Models\User $buyer): void
    {
        $deal = $event->deal;

        $this->sendEmail($buyer, new PostPurchaseDeliveryReadyNotification($deal));

        if ($buyer->phone) {
            $vehicle = $deal->vehicle;
            $vehicleLabel = $vehicle ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}" : 'your vehicle';
            $dealerPhone  = $deal->dealer->phone ?? null;

            $sms = "AutoClose: Your {$vehicleLabel} is ready! 🚗";
            if ($dealerPhone) {
                $sms .= " Our team will reach out to confirm delivery, or call us at {$dealerPhone}.";
            } else {
                $sms .= " Our team will reach out to confirm your delivery time.";
            }

            $this->sendSms($buyer->phone, $sms);
        }
    }

    private function handleDelivered(DealStatusChanged $event, \App\Models\User $buyer): void
    {
        $deal = $event->deal;

        $this->sendEmail($buyer, new PostPurchaseDeliveredNotification($deal));

        if ($buyer->phone) {
            $vehicle    = $deal->vehicle;
            $reviewUrl  = $deal->dealer->google_review_url ?? null;
            $vehicleLabel = $vehicle ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}" : 'your vehicle';

            $sms = "AutoClose: Welcome to the family! 🎉 Your {$vehicleLabel} has been delivered.";
            if ($reviewUrl) {
                $sms .= " We'd love your review: {$reviewUrl}";
            }

            $this->sendSms($buyer->phone, $sms);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function sendEmail(\App\Models\User $buyer, \Illuminate\Notifications\Notification $notification): void
    {
        try {
            $buyer->notify($notification);
        } catch (\Throwable $e) {
            Log::error('Post-purchase email failed', [
                'deal_id'           => method_exists($notification, 'deal') ? null : null,
                'notification_class' => get_class($notification),
                'error'             => $e->getMessage(),
            ]);
        }
    }

    private function sendSms(string $phone, string $body): void
    {
        try {
            $this->twilio->sendSms($phone, $body);
        } catch (\Throwable $e) {
            Log::error('Post-purchase SMS failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
