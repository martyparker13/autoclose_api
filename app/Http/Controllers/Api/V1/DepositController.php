<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Deal;
use App\Services\DealService;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Exception\SignatureVerificationException;

class DepositController extends BaseController
{
    public function __construct(
        private readonly DealService   $dealService,
        private readonly StripeService $stripe,
    ) {}

    /**
     * POST /deals/{deal}/deposit
     *
     * Buyer requests a PaymentIntent to pay the refundable deposit.
     * Returns the Stripe client_secret needed to confirm payment on the client.
     */
    public function create(Request $request, Deal $deal): JsonResponse
    {
        Gate::authorize('view', $deal);

        if ($deal->deposit_paid_at !== null) {
            return $this->errorResponse('Deposit has already been paid.', 409);
        }

        // Deposit amount: configurable per dealer, defaults to $500
        $depositCents = (int) ($deal->dealer->settings['deposit_amount_cents'] ?? 50000);

        try {
            $result = $this->stripe->createDepositIntent($deal, $depositCents);
        } catch (RuntimeException $e) {
            Log::error('Stripe createDepositIntent failed', ['deal_id' => $deal->id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Payment service unavailable. Please try again.', 503);
        }

        return response()->json([
            'data' => [
                'client_secret'     => $result['client_secret'],
                'payment_intent_id' => $result['payment_intent_id'],
                'amount_cents'      => $depositCents,
            ],
        ]);
    }

    /**
     * POST /deals/{deal}/deposit/confirm
     *
     * Called after Stripe confirms the payment client-side.
     * Verifies the PaymentIntent server-side and marks the deal deposit paid.
     */
    public function confirm(Request $request, Deal $deal): JsonResponse
    {
        Gate::authorize('view', $deal);

        $data = $request->validate([
            'payment_intent_id' => ['required', 'string'],
        ]);

        if ($deal->deposit_paid_at !== null) {
            return $this->errorResponse('Deposit has already been confirmed.', 409);
        }

        try {
            $intent = $this->stripe->verifyPayment($data['payment_intent_id']);
        } catch (RuntimeException $e) {
            Log::error('Stripe verifyPayment failed', ['deal_id' => $deal->id, 'pi' => $data['payment_intent_id'], 'error' => $e->getMessage()]);
            return $this->errorResponse('Payment could not be verified: ' . $e->getMessage(), 422);
        }

        $deal->update([
            'deposit_paid_at'    => now(),
            'deposit_payment_id' => $intent->id,
            'deposit_amount'     => $intent->amount,
        ]);

        return response()->json(['data' => ['message' => 'Deposit confirmed.', 'deal_id' => $deal->id]]);
    }

    /**
     * POST /webhooks/stripe
     *
     * Stripe sends webhook events here (signed with STRIPE_WEBHOOK_SECRET).
     * Only payment_intent.succeeded is handled for now.
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature', '');

        try {
            $event = $this->stripe->constructWebhookEvent($payload, $sigHeader);
        } catch (SignatureVerificationException $e) {
            return $this->errorResponse('Invalid signature.', 400);
        } catch (RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }

        if ($event->type === 'payment_intent.succeeded') {
            /** @var \Stripe\PaymentIntent $pi */
            $pi     = $event->data->object;
            $dealId = $pi->metadata['deal_id'] ?? null;

            if ($dealId) {
                $deal = Deal::find((int) $dealId);
                if ($deal && $deal->deposit_paid_at === null) {
                    $deal->update([
                        'deposit_paid_at'    => now(),
                        'deposit_payment_id' => $pi->id,
                        'deposit_amount'     => $pi->amount,
                    ]);
                }
            }
        }

        return response()->json(['received' => true]);
    }
}
