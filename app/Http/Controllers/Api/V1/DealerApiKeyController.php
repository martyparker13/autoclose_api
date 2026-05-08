<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DealerApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages dealer API keys used for server-to-server inventory sync.
 *
 * All routes require auth:sanctum + role:dealer_admin + tenant.
 */
class DealerApiKeyController extends BaseController
{
    /** GET /dealer/api-keys */
    public function index(): JsonResponse
    {
        $dealer = app('current_dealer');

        $keys = DealerApiKey::where('dealer_id', $dealer->id)
            ->orderByDesc('created_at')
            ->get(['id', 'label', 'key_prefix', 'last_used_at', 'expires_at', 'created_at']);

        return response()->json(['data' => $keys]);
    }

    /**
     * POST /dealer/api-keys
     *
     * Creates a new key and returns the raw value ONCE.
     * The raw key is never stored — only its SHA-256 hash.
     */
    public function store(Request $request): JsonResponse
    {
        $dealer = app('current_dealer');

        $data = $request->validate([
            'label'      => ['required', 'string', 'max:100'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $generated = DealerApiKey::generate();

        $key = DealerApiKey::create([
            'dealer_id'  => $dealer->id,
            'label'      => $data['label'],
            'key_hash'   => $generated['hash'],
            'key_prefix' => $generated['prefix'],
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'id'         => $key->id,
                'label'      => $key->label,
                'key_prefix' => $key->key_prefix,
                'expires_at' => $key->expires_at,
                'created_at' => $key->created_at,
                // Raw key shown ONCE — not stored, cannot be recovered
                'raw_key'    => $generated['raw'],
            ],
        ], 201);
    }

    /** DELETE /dealer/api-keys/{keyId} */
    public function destroy(int $keyId): JsonResponse
    {
        $dealer = app('current_dealer');

        $key = DealerApiKey::where('dealer_id', $dealer->id)->findOrFail($keyId);
        $key->delete();

        return response()->json(null, 204);
    }
}
