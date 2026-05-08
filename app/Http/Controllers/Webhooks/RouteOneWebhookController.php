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
 * POST /webhooks/routeone
 *
 * Receives credit decision callbacks from RouteOne.
 *
 * Signature verification:
 *   If ROUTEONE_WEBHOOK_SECRET is set, the raw body is verified against the
 *   X-RouteOne-Signature header (HMAC-SHA256).
 *   Without the env var the check is skipped (safe for local dev / pre-approval).
 *
 * Expected payload:
 * {
 *   "event":            "credit_decision",
 *   "application_id":   "RO-12345",
 *   "dealer_code":      "DEALER-123",
 *   "decision":         "approved",      // approved | declined | conditional
 *   "approved_amount":  3500000,          // cents
 *   "approved_rate":    5.99,
 *   "approved_term":    60
 * }
 */
class RouteOneWebhookController extends BaseController
{
    public function __construct(
        private readonly CreditDecisionService $creditDecisions,
        private readonly EContractService $eContracts,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();

        // ── Signature verification ─────────────────────────────────────────
        $secret = config('services.routeone.webhook_secret');

        if ($secret) {
            $signature = $request->header('X-RouteOne-Signature', '');
            $expected  = 'sha256='.hash_hmac('sha256', $rawPayload, $secret);

            if (! hash_equals($expected, $signature)) {
                Log::warning('RouteOne webhook: invalid signature');
                return $this->errorResponse('Forbidden', 403);
            }
        }

        // ── Parse ──────────────────────────────────────────────────────────
        $data = $request->all();

        $event         = $data['event']          ?? null;
        $applicationId = $data['application_id'] ?? null;
        $decision      = $data['decision']        ?? null;

        // ── eContract signed callback ──────────────────────────────────────
        if ($event === 'contract_signed') {
            $contractId = $data['contract_id'] ?? null;
            if ($contractId) {
                try {
                    $this->eContracts->handleSigned('routeone', (string) $contractId);
                } catch (\Throwable $e) {
                    Log::error('RouteOne webhook: eContract signed processing failed', ['error' => $e->getMessage()]);
                }
            } else {
                Log::warning('RouteOne webhook: contract_signed missing contract_id', ['payload' => $data]);
            }
            return response()->json(['received' => true]);
        }

        // Only process decision events
        if ($event !== 'credit_decision') {
            return response()->json(['received' => true]);
        }

        if (! $applicationId || ! $decision) {
            Log::warning('RouteOne webhook: missing application_id or decision', ['payload' => $data]);
            return $this->errorResponse('Bad Request', 400);
        }

        // ── Dispatch ───────────────────────────────────────────────────────
        try {
            $this->creditDecisions->handle(
                platform:       'routeone',
                externalId:     $applicationId,
                decision:       $decision,
                approvedAmount: isset($data['approved_amount']) ? (int) $data['approved_amount'] : null,
                approvedApr:    isset($data['approved_rate'])   ? (float) $data['approved_rate'] : null,
                approvedTerm:   isset($data['approved_term'])   ? (int) $data['approved_term']   : null,
            );
        } catch (ModelNotFoundException) {
            // Return 200 — RouteOne should not retry for unknown app IDs
            Log::warning('RouteOne webhook: no credit application found for application_id', [
                'application_id' => $applicationId,
            ]);
        } catch (\Throwable $e) {
            Log::error('RouteOne webhook: processing failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['received' => true]);
    }
}
