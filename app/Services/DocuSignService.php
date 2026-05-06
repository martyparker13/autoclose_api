<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealDocument;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * DocuSign JWT-auth service.
 *
 * Uses the eSignature REST API via raw cURL/Guzzle (no official PHP SDK dependency).
 * The official SDK can be substituted by swapping $this->post() / $this->get()
 * for the DocuSign client once installed.
 *
 * OAuth flow: Server-side JWT Grant (impersonation of a service account).
 */
class DocuSignService
{
    private ?string $accessToken   = null;
    private ?int    $tokenExpiresAt = null;

    private string $baseApi;
    private string $accountId;
    private string $oauthBase;
    private string $clientId;
    private string $impersonateUserId;
    private string $privateKeyPath;
    private string $webhookSecret;

    public function __construct()
    {
        $this->baseApi           = rtrim(config('services.docusign.base_path'), '/');
        $this->accountId         = config('services.docusign.account_id');
        $this->oauthBase         = config('services.docusign.oauth_base');
        $this->clientId          = config('services.docusign.client_id');
        $this->impersonateUserId = config('services.docusign.impersonate_user');
        $this->privateKeyPath    = config('services.docusign.private_key_path');
        $this->webhookSecret     = config('services.docusign.webhook_secret', '');
    }

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Create a DocuSign envelope for a deal and send it to the buyer for signing.
     *
     * @param  list<DealDocument>|null  $documents  Specific documents to send; null = all pending.
     * @return string  The DocuSign envelope ID.
     *
     * @throws RuntimeException
     */
    public function createAndSendEnvelope(Deal $deal, User $buyer, ?array $documents = null): string
    {
        $token = $this->getAccessToken();
        $docs  = $documents ?? $deal->documents()->whereNull('docusign_envelope_id')->get()->all();

        if (empty($docs)) {
            throw new RuntimeException('No documents available to send for signature.');
        }

        // Build composite template envelope
        $signersArr = [];
        $docsArr    = [];
        $tabsArr    = [];

        foreach ($docs as $idx => $doc) {
            $docNum  = $idx + 1;
            $content = $this->documentContent($doc);

            $docsArr[] = [
                'documentBase64' => base64_encode($content),
                'name'           => $this->documentLabel($doc),
                'fileExtension'  => 'pdf',
                'documentId'     => (string) $docNum,
            ];

            $tabsArr["$docNum"] = [
                'signHereTabs' => [
                    ['anchorString' => '/sig1/', 'anchorXOffset' => '0', 'anchorYOffset' => '0', 'documentId' => (string) $docNum],
                ],
                'dateSignedTabs' => [
                    ['anchorString' => '/date1/', 'anchorXOffset' => '0', 'anchorYOffset' => '0', 'documentId' => (string) $docNum],
                ],
            ];
        }

        $signersArr[] = [
            'email'        => $buyer->email,
            'name'         => $buyer->name,
            'recipientId'  => '1',
            'routingOrder' => '1',
            'tabs'         => array_merge_recursive(...array_values($tabsArr)),
        ];

        $envelopeDefinition = [
            'emailSubject' => "Please sign your documents for deal #{$deal->id}",
            'documents'    => $docsArr,
            'recipients'   => ['signers' => $signersArr],
            'status'       => 'sent',
            'eventNotification' => $this->buildWebhookNotification(),
        ];

        $url      = "{$this->baseApi}/v2.1/accounts/{$this->accountId}/envelopes";
        $response = $this->post($url, $envelopeDefinition, $token);

        if (empty($response['envelopeId'])) {
            throw new RuntimeException('DocuSign did not return an envelope ID. Response: ' . json_encode($response));
        }

        $envelopeId = $response['envelopeId'];

        // Store envelope ID on each document
        foreach ($docs as $doc) {
            $doc->update([
                'docusign_envelope_id' => $envelopeId,
                'docusign_status'      => 'sent',
            ]);
        }

        return $envelopeId;
    }

    /**
     * Generate a time-limited recipient view (embedded signing) URL.
     *
     * @throws RuntimeException
     */
    public function getSigningUrl(string $envelopeId, User $signer, string $returnUrl): string
    {
        $token = $this->getAccessToken();
        $url   = "{$this->baseApi}/v2.1/accounts/{$this->accountId}/envelopes/{$envelopeId}/views/recipient";

        $body = [
            'authenticationMethod' => 'none',
            'clientUserId'         => (string) $signer->id,
            'email'                => $signer->email,
            'userName'             => $signer->name,
            'returnUrl'            => $returnUrl,
        ];

        $response = $this->post($url, $body, $token);

        if (empty($response['url'])) {
            throw new RuntimeException('DocuSign did not return a signing URL.');
        }

        return $response['url'];
    }

    /**
     * Process an incoming DocuSign Connect webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): void
    {
        $envelopeId = $payload['envelopeId'] ?? $payload['data']['envelopeId'] ?? null;
        $status     = strtolower($payload['event'] ?? $payload['data']['envelopeSummary']['status'] ?? '');

        if (! $envelopeId) {
            Log::warning('DocuSign webhook: missing envelopeId', $payload);
            return;
        }

        $documents = DealDocument::where('docusign_envelope_id', $envelopeId)->get();

        /** @var DealDocument $doc */
        foreach ($documents as $doc) {
            $doc->update([
                'docusign_status' => $status,
                'signed_at'       => in_array($status, ['completed', 'signed']) ? now() : $doc->signed_at,
            ]);
        }

        // If all documents on the deal are completed → advance deal status
        if ($status === 'completed' && $documents->isNotEmpty()) {
            $deal      = $documents->first()?->deal;
            $allSigned = $deal?->documents()->where('docusign_status', '!=', 'completed')->doesntExist();

            if ($allSigned && $deal && $deal->status === 'docs_pending') {
                $deal->update(['status' => 'docs_signed']);
            }
        }
    }

    /**
     * Verify the HMAC signature from a DocuSign Connect request.
     *
     * @param  string  $payload     Raw request body.
     * @param  string  $signature   Value of X-DocuSign-Signature-1 header.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($this->webhookSecret)) {
            return true; // no secret configured — accept (dev mode)
        }

        $expected = base64_encode(hash_hmac('sha256', $payload, $this->webhookSecret, true));

        return hash_equals($expected, $signature);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Obtain a JWT-grant Bearer token (cached until expiry).
     *
     * @throws RuntimeException
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken && $this->tokenExpiresAt > time() + 60) {
            return $this->accessToken;
        }

        if (! file_exists($this->privateKeyPath)) {
            throw new RuntimeException("DocuSign private key not found at {$this->privateKeyPath}");
        }

        $privateKey = file_get_contents($this->privateKeyPath);
        $now        = time();

        $header  = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims  = base64_encode(json_encode([
            'iss'   => $this->clientId,
            'sub'   => $this->impersonateUserId,
            'aud'   => $this->oauthBase,
            'iat'   => $now,
            'exp'   => $now + 3600,
            'scope' => 'signature',
        ]));

        $signingInput = "{$header}.{$claims}";
        openssl_sign($signingInput, $sigRaw, $privateKey, OPENSSL_ALGO_SHA256);
        $sig = base64_encode($sigRaw);

        $jwt = "{$signingInput}.{$sig}";

        $response = $this->post(
            "https://{$this->oauthBase}/oauth/token",
            ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt],
            null,
            'application/x-www-form-urlencoded'
        );

        if (empty($response['access_token'])) {
            throw new RuntimeException('DocuSign JWT grant failed: ' . json_encode($response));
        }

        $this->accessToken    = $response['access_token'];
        $this->tokenExpiresAt = $now + ($response['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function post(string $url, array $body, ?string $token, string $contentType = 'application/json'): array
    {
        $ch = curl_init($url);

        $headers = ['Accept: application/json', "Content-Type: {$contentType}"];
        if ($token) {
            $headers[] = "Authorization: Bearer {$token}";
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $contentType === 'application/json'
                ? json_encode($body)
                : http_build_query($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("DocuSign HTTP error: {$err}");
        }

        $data = json_decode($raw, true) ?? [];

        if ($code >= 400) {
            $msg = $data['message'] ?? $raw;
            throw new RuntimeException("DocuSign API error ({$code}): {$msg}");
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWebhookNotification(): array
    {
        $webhookUrl = config('app.url') . '/api/v1/webhooks/docusign';

        return [
            'url'                       => $webhookUrl,
            'loggingEnabled'            => true,
            'requireAcknowledgment'     => true,
            'useSoapInterface'          => false,
            'includeCertificateWithSoap'=> false,
            'signMessageWithX509Cert'   => false,
            'includeDocuments'          => false,
            'includeEnvelopeVoidReason' => true,
            'includeTimeZone'           => true,
            'includeSenderAccountAsCustomField' => false,
            'includeDocumentFields'     => false,
            'envelopeEvents' => [
                ['envelopeEventStatusCode' => 'Sent'],
                ['envelopeEventStatusCode' => 'Delivered'],
                ['envelopeEventStatusCode' => 'Completed'],
                ['envelopeEventStatusCode' => 'Declined'],
                ['envelopeEventStatusCode' => 'Voided'],
            ],
        ];
    }

    private function documentLabel(DealDocument $doc): string
    {
        return ucwords(str_replace('_', ' ', $doc->type));
    }

    /**
     * Generate placeholder PDF content for a document.
     * In production, this would render a proper PDF template.
     */
    private function documentContent(DealDocument $doc): string
    {
        // Minimal valid single-page PDF with signature placeholders
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
            . "4 0 obj<</Length 80>>stream\nBT /F1 12 Tf 100 700 Td ("
            . addslashes(strtoupper(str_replace('_', ' ', $doc->type)))
            . ") Tj ET\n/sig1/ /date1/\nendstream\nendobj\n"
            . "5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
            . "xref\n0 6\n0000000000 65535 f\n"
            . "trailer<</Size 6/Root 1 0 R>>\nstartxref\n0\n%%EOF";
    }
}
