<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Deal;
use App\Services\DocuSignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DocuSignWebhookController extends BaseController
{
    public function __construct(
        private readonly DocuSignService $docuSign,
    ) {}

    /**
     * POST /webhooks/docusign
     *
     * Receives DocuSign Connect event notifications.
     * Signature is verified via HMAC-SHA256 if DOCUSIGN_WEBHOOK_HMAC_SECRET is set.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload   = $request->getContent();
        $signature = $request->header('X-DocuSign-Signature-1', '');

        if (! $this->docuSign->verifyWebhookSignature($payload, $signature)) {
            Log::warning('DocuSign webhook: invalid signature');
            return $this->errorResponse('Forbidden', 403);
        }

        try {
            $this->docuSign->handleWebhook($request->all());
        } catch (\Throwable $e) {
            Log::error('DocuSign webhook processing failed', ['error' => $e->getMessage()]);
            // Return 200 to prevent DocuSign from retrying for internal errors
        }

        return response()->json(['received' => true]);
    }
}
