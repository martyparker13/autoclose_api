<?php

namespace App\Notifications;

use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired when a deal reaches `awaiting_delivery`.
 * Tells the buyer their vehicle is ready and delivery is being arranged.
 */
class PostPurchaseDeliveryReadyNotification extends Notification implements ShouldQueue
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
        $vehicle    = $this->deal->vehicle;
        $dealerName = $this->deal->dealer->name ?? 'AutoClose';
        $dealerPhone = $this->deal->dealer->phone ?? null;
        $dealUrl    = config('app.frontend_url') . '/deals/' . $this->deal->id;

        $vehicleLabel = $vehicle
            ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}"
            : 'your vehicle';

        $message = (new MailMessage)
            ->subject("Your {$vehicleLabel} is ready for delivery!")
            ->greeting("Great news, {$notifiable->name}!")
            ->line("Your **{$vehicleLabel}** is ready and waiting for you.")
            ->line("We're in the process of finalising your delivery. Our team will be in touch very shortly to confirm your preferred time and location.");

        if ($dealerPhone) {
            $message->line("You can also reach us directly at **{$dealerPhone}**.");
        }

        $message
            ->action('View Your Deal', $dealUrl)
            ->salutation("— The {$dealerName} Team");

        return $message;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'post_purchase.delivery_ready',
            'deal_id' => $this->deal->id,
        ];
    }
}
