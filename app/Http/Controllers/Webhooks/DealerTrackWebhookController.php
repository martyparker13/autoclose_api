<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Api\V1\BaseController;
use App\Services\Integrations\CreditDecisionService;
use App\Services\Integrations\EContractService;
use App\Services\Integrations\WebhookDeduplicationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/dealertrack
 *
 * Receives credit decision callbacks from Cox Automotive / DealerTrack.
 *
 * Signature verification:
 *   If DEALERTRACK_WEBHOOK_SECRET is set, the raw request body is verified
 *   against the X-DealerTrack-Signature header (HMAC-SHA256).
 *   Without the env var the check is skipped (safe for local dev / pre-approval).
 *
 * Expected payload:
 * {
 *   "eventType":     "APPLICATION_DECISION_UPDATED",
 *   "applicationId": "DT-12345",
 *   "dealerId":      "DEALER-123",
 *   "decision": {
 *     "status":     "APPROVED",      // APPROVED | DECLINED | COUNTER_OFFER | CONDITIONAL
 *     "amount":      3500000,         // cents
 *     "apr":         5.99,
 *     "termMonths":  60
 *   },
 *   "timestamp": "2026-05-07T12:00:00Z"
 * }
 */
class DealerTrackWebhookController extends BaseController
{
    public function __construct(
        private readonly CreditDecisionService $creditDecisions,
        private readonly EContractService $eContracts,
        private readonly WebhookDeduplicationService $dedupe,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();

        // ── Signature verification ─────────────────────────────────────────
        $secret = config('services.dealertrack.webhook_secret');

        if ($secret) {
            $signature = $request->header('X-DealerTrack-Signature', '');
            $expected  = 'sha256='.hash_hmac('sha256', $rawPayload, $secret);

            if (! hash_equals($expected, $signature)) {
                $this->dedupe->recordRejected('dealertrack', 'signature_invalid', $rawPayload, 'Invalid webhook signature', $request->all());
                Log::warning('DealerTrack webhook: invalid signature');
                return $this->errorResponse('Forbidden', 403);
            }
        }

        // ── Parse ──────────────────────────────────────────────────────────
        $data = $request->all();

        $eventType    = $data['eventType']    ?? null;
        $applicationId = $data['applicationId'] ?? null;
        $decisionData  = $data['decision']      ?? [];
        $status        = $decisionData['status'] ?? null;
        $eventKey = $this->eventKey($eventType, $data, $applicationId, $status);
        $dedupeResult = $this->dedupe->begin('dealertrack', $eventKey, $rawPayload, $data);

        if ($dedupeResult['duplicate']) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        $webhookEvent = $dedupeResult['event'];

        // ── eContract signed callback ──────────────────────────────────────
        if ($eventType === 'CONTRACT_SIGNED' || $eventType === 'ECONTRACT_SIGNED') {
            $contractId = $data['contractId'] ?? $data['applicationId'] ?? null;
            if ($contractId) {
                try {
                    $this->eContracts->handleSigned('dealertrack', (string) $contractId);
                    $this->dedupe->markProcessed($webhookEvent);
                } catch (\Throwable $e) {
                    $this->dedupe->markFailed($webhookEvent, 'eContract signed processing failed: '.$e->getMessage());
                    Log::error('DealerTrack webhook: eContract signed processing failed', ['error' => $e->getMessage()]);
                }
            } else {
                $this->dedupe->markFailed($webhookEvent, 'CONTRACT_SIGNED missing contractId');
                Log::warning('DealerTrack webhook: CONTRACT_SIGNED missing contractId', ['payload' => $data]);
            }
            return response()->json(['received' => true]);
        }

        // Only process decision events
        if ($eventType !== 'APPLICATION_DECISION_UPDATED') {
            $this->dedupe->markProcessed($webhookEvent);
            return response()->json(['received' => true]);
        }

        if (! $applicationId || ! $status) {
            $this->dedupe->markFailed($webhookEvent, 'Missing applicationId or decision.status');
            Log::warning('DealerTrack webhook: missing applicationId or decision.status', ['payload' => $data]);
            return $this->errorResponse('Bad Request', 400);
        }

        // ── Dispatch ───────────────────────────────────────────────────────
        try {
            $this->creditDecisions->handle(
                platform:       'dealertrack',
                externalId:     $applicationId,
                decision:       $status,
                approvedAmount: isset($decisionData['amount']) ? (int) $decisionData['amount'] : null,
                approvedApr:    isset($decisionData['apr'])    ? (float) $decisionData['apr']  : null,
                approvedTerm:   isset($decisionData['termMonths']) ? (int) $decisionData['termMonths'] : null,
            );
            $this->dedupe->markProcessed($webhookEvent);
        } catch (ModelNotFoundException) {
            $this->dedupe->markProcessed($webhookEvent);
            // Return 200 — DealerTrack should not retry for unknown app IDs
            Log::warning('DealerTrack webhook: no credit application found for externalId', [
                'application_id' => $applicationId,
            ]);
        } catch (\Throwable $e) {
            $this->dedupe->markFailed($webhookEvent, 'Credit decision processing failed: '.$e->getMessage());
            Log::error('DealerTrack webhook: processing failed', ['error' => $e->getMessage()]);
            // Return 200 to prevent endless retries for internal errors
        }

        return response()->json(['received' => true]);
    }

    /** @param array<string, mixed> $data */
    private function eventKey(?string $eventType, array $data, ?string $applicationId, ?string $status): string
    {
        if ($eventType === 'CONTRACT_SIGNED' || $eventType === 'ECONTRACT_SIGNED') {
            $contractId = (string) ($data['contractId'] ?? $applicationId ?? 'unknown');
            return $eventType.':'.$contractId;
        }

        if ($eventType === 'APPLICATION_DECISION_UPDATED') {
            return $eventType.':'.($applicationId ?? 'unknown').':'.strtolower((string) ($status ?? 'unknown'));
        }

        return (string) ($eventType ?? 'unknown').':'.($applicationId ?? 'unknown');
    }
}
