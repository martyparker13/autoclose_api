<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Api\V1\BaseController;
use App\Services\Integrations\CreditDecisionService;
use App\Services\Integrations\EContractService;
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

        // ── eContract signed callback ──────────────────────────────────────
        if ($eventType === 'CONTRACT_SIGNED' || $eventType === 'ECONTRACT_SIGNED') {
            $contractId = $data['contractId'] ?? $data['applicationId'] ?? null;
            if ($contractId) {
                try {
                    $this->eContracts->handleSigned('dealertrack', (string) $contractId);
                } catch (\Throwable $e) {
                    Log::error('DealerTrack webhook: eContract signed processing failed', ['error' => $e->getMessage()]);
                }
            } else {
                Log::warning('DealerTrack webhook: CONTRACT_SIGNED missing contractId', ['payload' => $data]);
            }
            return response()->json(['received' => true]);
        }

        // Only process decision events
        if ($eventType !== 'APPLICATION_DECISION_UPDATED') {
            return response()->json(['received' => true]);
        }

        if (! $applicationId || ! $status) {
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
        } catch (ModelNotFoundException) {
            // Return 200 — DealerTrack should not retry for unknown app IDs
            Log::warning('DealerTrack webhook: no credit application found for externalId', [
                'application_id' => $applicationId,
            ]);
        } catch (\Throwable $e) {
            Log::error('DealerTrack webhook: processing failed', ['error' => $e->getMessage()]);
            // Return 200 to prevent endless retries for internal errors
        }

        return response()->json(['received' => true]);
    }
}
