<?php

namespace App\Jobs;

use App\Models\DeliveryAppointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Twilio\Rest\Client as TwilioClient;

class SendDeliveryReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public int $tries = 3;

    /**
     * @param  int     $appointmentId  ID of the DeliveryAppointment
     * @param  string  $hoursLabel     Human-readable label, e.g. '24 hours' or '1 hour'
     */
    public function __construct(
        private readonly int $appointmentId,
        private readonly string $hoursLabel,
    ) {}

    public function handle(): void
    {
        $appointment = DeliveryAppointment::with(['deal.buyer'])->find($this->appointmentId);

        // Bail silently if appointment was cancelled / completed since job was queued
        if (! $appointment || $appointment->status !== 'scheduled') {
            return;
        }

        $buyer = $appointment->deal?->buyer;

        if (! $buyer?->phone) {
            return;
        }

        $type    = ucfirst($appointment->type);
        $dateStr = $appointment->scheduled_at->format('M j, g:i A');

        $twilio = new TwilioClient(
            (string) config('services.twilio.sid'),
            (string) config('services.twilio.token'),
        );

        $twilio->messages->create($buyer->phone, [
            'from' => config('services.twilio.from'),
            'body' => "AutoClose Reminder: Your vehicle {$type} is in {$this->hoursLabel} ({$dateStr}). Reply STOP to opt out.",
        ]);
    }
}
