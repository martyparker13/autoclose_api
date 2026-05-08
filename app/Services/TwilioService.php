<?php

namespace App\Services;

use Twilio\Rest\Client;
use Twilio\Exceptions\TwilioException;
use RuntimeException;

class TwilioService
{
    private ?Client $client = null;

    /**
     * Send an SMS message to the given phone number.
     *
     * @throws TwilioException
     */
    public function sendSms(string $to, string $body): void
    {
        $sid = (string) config('services.twilio.sid');
        $token = (string) config('services.twilio.token');
        $from = (string) config('services.twilio.from');

        if ($sid === '' || $token === '' || $from === '') {
            if (app()->environment('testing')) {
                return;
            }

            throw new RuntimeException('Twilio credentials are not configured.');
        }

        if ($this->client === null) {
            $this->client = new Client($sid, $token);
        }

        $this->client->messages->create($to, [
            'from' => $from,
            'body' => $body,
        ]);
    }
}
