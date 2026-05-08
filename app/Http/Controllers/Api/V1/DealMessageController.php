<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DealMessageResource;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DealMessageController extends Controller
{
    /**
     * List messages for a deal.
     * Accessible to the buyer who owns the deal and dealer staff.
     */
    public function index(Request $request, int $dealId): AnonymousResourceCollection
    {
        $deal = $this->resolveDeal($request, $dealId);

        $messages = $deal->messages()->with('sender')->get();

        // Mark unread messages (not sent by current user) as read
        $deal->messages()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $request->user()->id)
            ->update(['read_at' => now()]);

        return DealMessageResource::collection($messages);
    }

    /**
     * Post a new message to a deal thread.
     */
    public function store(Request $request, int $dealId): JsonResponse
    {
        $deal = $this->resolveDeal($request, $dealId);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $message = $deal->messages()->create([
            'sender_id' => $request->user()->id,
            'body'      => $validated['body'],
        ]);

        $message->load('sender');

        return response()->json(['data' => new DealMessageResource($message)], 201);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Resolve a deal that the current user is authorised to access.
     * Buyers can only access their own deals; dealer staff can access any deal in their dealer.
     */
    private function resolveDeal(Request $request, int $dealId): Deal
    {
        $user = $request->user();

        if (in_array($user->role, ['dealer_admin', 'dealer_staff', 'super_admin'], true)) {
            return Deal::where('dealer_id', $user->dealer_id)
                ->findOrFail($dealId);
        }

        return Deal::where('buyer_id', $user->id)
            ->findOrFail($dealId);
    }
}
