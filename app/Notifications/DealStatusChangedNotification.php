<?php

namespace App\Notifications;

use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DealStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Deal $deal,
        public readonly string $oldStatus,
        public readonly string $newStatus,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $statusLabel = str_replace('_', ' ', ucfirst($this->newStatus));
        $dealUrl     = config('app.frontend_url') . '/deals/' . $this->deal->id;

        return (new MailMessage)
            ->subject("Deal #{$this->deal->id} — Status Updated to {$statusLabel}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your deal for the {$this->deal->vehicle?->year} {$this->deal->vehicle?->make} {$this->deal->vehicle?->model} has been updated.")
            ->line("New status: **{$statusLabel}**")
            ->action('View Deal', $dealUrl)
            ->line('Thank you for choosing AutoClose.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'deal_id'    => $this->deal->id,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
        ];
    }
}
