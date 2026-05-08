<?php

namespace App\Notifications;

use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DealWorkflowActionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Deal $deal,
        public readonly string $title,
        public readonly string $message,
        public readonly string $actionPath,
        public readonly string $workflowEvent,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/') . $this->actionPath;

        return (new MailMessage)
            ->subject($this->title)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message)
            ->action('Open Deal', $url)
            ->line('AutoClose workflow automation generated this reminder.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'deal_id'         => $this->deal->id,
            'dealer_id'       => $this->deal->dealer_id,
            'title'           => $this->title,
            'message'         => $this->message,
            'action_path'     => $this->actionPath,
            'workflow_event'  => $this->workflowEvent,
        ];
    }
}
