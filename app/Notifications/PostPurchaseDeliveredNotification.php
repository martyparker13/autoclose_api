<?php

namespace App\Notifications;

use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired when a deal reaches `delivered`.
 * Welcomes the buyer to ownership and requests a review.
 */
class PostPurchaseDeliveredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Deal $deal,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vehicle      = $this->deal->vehicle;
        $dealer       = $this->deal->dealer;
        $dealerName   = $dealer->name ?? 'AutoClose';
        $dealerPhone  = $dealer->phone ?? null;
        $reviewUrl    = $dealer->google_review_url ?? null;
        $dealUrl      = config('app.frontend_url') . '/deals/' . $this->deal->id;

        $vehicleLabel = $vehicle
            ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}"
            : 'your vehicle';

        $message = (new MailMessage)
            ->subject("Welcome to the family — enjoy your {$vehicleLabel}! 🚗")
            ->greeting("Congratulations, {$notifiable->name}!")
            ->line("Your **{$vehicleLabel}** has been delivered. We hope you love every mile!")
            ->line("**What's next:**")
            ->line("• Keep your signed documents somewhere safe — a copy is always available in your deal portal.")
            ->line("• Schedule your first service at the intervals recommended in your owner's manual.");

        if ($dealerPhone) {
            $message->line("• Have questions? Our service team is available at **{$dealerPhone}**.");
        }

        $message->action('View Your Deal Portal', $dealUrl);

        if ($reviewUrl) {
            $message
                ->line('---')
                ->line("If we made your purchase experience easy, we'd really appreciate a quick Google review — it helps other buyers find us!")
                ->action('Leave a Google Review', $reviewUrl);
        }

        $message->salutation("— The {$dealerName} Team");

        return $message;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'post_purchase.delivered',
            'deal_id' => $this->deal->id,
        ];
    }
}
