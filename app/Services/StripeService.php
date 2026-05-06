<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Dealer;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeService
{
    private const DEPOSIT_CURRENCY = 'usd';

    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        Stripe::setApiVersion('2023-10-16');
    }

    /**
     * Create a Stripe PaymentIntent for a deal deposit.
     *
     * @param  int  $amountCents  Amount in cents (e.g. 50000 for $500.00)
     * @return array{client_secret: string, payment_intent_id: string}
     *
     * @throws RuntimeException
     */
    public function createDepositIntent(Deal $deal, int $amountCents): array
    {
        try {
            $intent = PaymentIntent::create([
                'amount'               => $amountCents,
                'currency'             => self::DEPOSIT_CURRENCY,
                'automatic_payment_methods' => ['enabled' => true],
                'metadata'             => [
                    'deal_id'   => $deal->id,
                    'dealer_id' => $deal->dealer_id,
                    'buyer_id'  => $deal->buyer_id,
                ],
                'description'          => "Deposit — deal #{$deal->id}",
            ]);

            return [
                'client_secret'     => $intent->client_secret,
                'payment_intent_id' => $intent->id,
            ];
        } catch (ApiErrorException $e) {
            throw new RuntimeException('Failed to create payment intent: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Retrieve a PaymentIntent and verify it succeeded.
     *
     * @throws RuntimeException  If payment has not succeeded.
     */
    public function verifyPayment(string $paymentIntentId): PaymentIntent
    {
        try {
            $intent = PaymentIntent::retrieve($paymentIntentId);
        } catch (ApiErrorException $e) {
            throw new RuntimeException('Could not retrieve payment: ' . $e->getMessage(), 0, $e);
        }

        if ($intent->status !== 'succeeded') {
            throw new RuntimeException("Payment has not succeeded (status: {$intent->status})");
        }

        return $intent;
    }

    /**
     * Construct and validate a Stripe webhook event from a raw request.
     *
     * @throws \Stripe\Exception\SignatureVerificationException
     * @throws RuntimeException
     */
    public function constructWebhookEvent(string $payload, string $sigHeader): \Stripe\Event
    {
        $secret = config('services.stripe.webhook_secret');

        if (empty($secret)) {
            throw new RuntimeException('Stripe webhook secret is not configured.');
        }

        return Webhook::constructEvent($payload, $sigHeader, $secret);
    }
}
