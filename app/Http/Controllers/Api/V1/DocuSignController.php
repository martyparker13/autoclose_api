<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Deal;
use App\Services\DocuSignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DocuSignController extends BaseController
{
    public function __construct(
        private readonly DocuSignService $docuSign,
    ) {}

    /**
     * POST /dealer/deals/{deal}/documents/send-for-signature
     *
     * Dealer staff/admin sends the deal's pending documents to the buyer via DocuSign.
     */
    public function sendForSignature(Request $request, Deal $deal): JsonResponse
    {
        Gate::authorize('update', $deal);

        try {
            $buyer      = $deal->buyer;
            $envelopeId = $this->docuSign->createAndSendEnvelope($deal, $buyer);
        } catch (RuntimeException $e) {
            Log::error('DocuSign sendForSignature failed', [
                'deal_id' => $deal->id,
                'error'   => $e->getMessage(),
            ]);
            return $this->errorResponse('Document signing service unavailable: ' . $e->getMessage(), 503);
        }

        return response()->json([
            'data' => [
                'envelope_id' => $envelopeId,
                'message'     => 'Documents sent for signature.',
            ],
        ]);
    }

    /**
     * GET /deals/{deal}/documents/signing-url?return_url=https://...
     *
     * Returns an embedded DocuSign signing session URL for the buyer.
     * The buyer's browser is redirected to DocuSign, then back to return_url when done.
     */
    public function signingUrl(Request $request, Deal $deal): JsonResponse
    {
        Gate::authorize('view', $deal);

        $data = $request->validate([
            'return_url' => ['required', 'url', 'max:500'],
        ]);

        // Find the first pending envelope on this deal
        $document = $deal->documents()->whereNotNull('docusign_envelope_id')
            ->where('docusign_status', '!=', 'completed')
            ->first();

        if (! $document) {
            return $this->errorResponse('No pending documents to sign.', 404);
        }

        try {
            $url = $this->docuSign->getSigningUrl(
                $document->docusign_envelope_id,
                $request->user(),
                $data['return_url'],
            );
        } catch (RuntimeException $e) {
            Log::error('DocuSign getSigningUrl failed', [
                'deal_id' => $deal->id,
                'error'   => $e->getMessage(),
            ]);
            return $this->errorResponse('Could not create signing session: ' . $e->getMessage(), 503);
        }

        return response()->json(['data' => ['url' => $url]]);
    }
}
