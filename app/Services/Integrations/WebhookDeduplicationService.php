<?php

namespace App\Services\Integrations;

use App\Models\IntegrationWebhookEvent;
use Illuminate\Database\QueryException;

class WebhookDeduplicationService
{
    /**
     * Register an inbound webhook delivery and determine if it is a duplicate.
     *
     * @param  array<string, mixed>  $payload
     * @return array{duplicate: bool, event: IntegrationWebhookEvent}
     */
    public function begin(string $provider, string $eventKey, string $rawPayload, array $payload = []): array
    {
        $hash = hash('sha256', $rawPayload);
        $now = now();

        $existing = IntegrationWebhookEvent::query()
            ->where('provider', $provider)
            ->where('event_key', $eventKey)
            ->where('payload_hash', $hash)
            ->first();

        if ($existing) {
            $existing->increment('delivery_count');
            $existing->update(['last_seen_at' => $now]);

            return ['duplicate' => true, 'event' => $existing->fresh()];
        }

        try {
            $event = IntegrationWebhookEvent::create([
                'provider' => $provider,
                'event_key' => $eventKey,
                'payload_hash' => $hash,
                'status' => 'received',
                'delivery_count' => 1,
                'payload' => $payload,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);

            return ['duplicate' => false, 'event' => $event];
        } catch (QueryException) {
            $event = IntegrationWebhookEvent::query()
                ->where('provider', $provider)
                ->where('event_key', $eventKey)
                ->where('payload_hash', $hash)
                ->firstOrFail();

            $event->increment('delivery_count');
            $event->update(['last_seen_at' => $now]);

            return ['duplicate' => true, 'event' => $event->fresh()];
        }
    }

    public function markProcessed(IntegrationWebhookEvent $event): void
    {
        $event->update([
            'status' => 'processed',
            'processed_at' => now(),
            'last_seen_at' => now(),
            'error' => null,
        ]);
    }

    public function markFailed(IntegrationWebhookEvent $event, string $reason): void
    {
        $event->update([
            'status' => 'failed',
            'error' => $reason,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordRejected(string $provider, string $eventKey, string $rawPayload, string $reason, array $payload = []): void
    {
        $result = $this->begin($provider, $eventKey, $rawPayload, $payload);
        $this->markFailed($result['event'], $reason);
    }
}
