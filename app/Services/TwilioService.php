<?php

namespace App\Services;

use Twilio\Rest\Client;
use Twilio\Exceptions\TwilioException;

class TwilioService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client(
            config('services.twilio.sid'),
            config('services.twilio.token'),
        );
    }

    /**
     * Send an SMS message to the given phone number.
     *
     * @throws TwilioException
     */
    public function sendSms(string $to, string $body): void
    {
        $this->client->messages->create($to, [
            'from' => config('services.twilio.from'),
            'body' => $body,
        ]);
    }
}
