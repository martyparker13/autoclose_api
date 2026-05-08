<?php

namespace App\Notifications;

use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired when a deal reaches `docs_signed`.
 * Congratulates the buyer and sets expectations for the delivery step.
 */
class PostPurchaseDocsSignedNotification extends Notification implements ShouldQueue
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
        $vehicle  = $this->deal->vehicle;
        $dealerName = $this->deal->dealer->name ?? 'AutoClose';
        $dealUrl  = config('app.frontend_url') . '/deals/' . $this->deal->id;

        $vehicleLabel = $vehicle
            ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}"
            : 'your vehicle';

        return (new MailMessage)
            ->subject("🎉 Your paperwork is signed — {$vehicleLabel}")
            ->greeting("Congratulations, {$notifiable->name}!")
            ->line("Great news — all documents for your **{$vehicleLabel}** have been signed and received.")
            ->line("Our team at {$dealerName} is now preparing for your delivery. You'll hear from us shortly to confirm the details.")
            ->action('View Your Deal', $dealUrl)
            ->line('Questions? Reply to this email or call us directly.')
            ->salutation("— The {$dealerName} Team");
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'post_purchase.docs_signed',
            'deal_id' => $this->deal->id,
        ];
    }
}
