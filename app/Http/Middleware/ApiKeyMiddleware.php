<?php

namespace App\Http\Middleware;

use App\Models\DealerApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates requests using a dealer API key sent in the X-API-Key header.
 *
 * If the key is valid the corresponding dealer is bound into the IoC container
 * as 'current_dealer' (same as TenantMiddleware) so that downstream controllers
 * work identically regardless of authentication method.
 *
 * Usage:
 *   Route::middleware('auth.api_key')->group(...)
 */
class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawKey = $request->header('X-API-Key');

        if (! $rawKey) {
            return response()->json(['message' => 'API key required.'], 401);
        }

        $hash   = hash('sha256', $rawKey);
        $apiKey = DealerApiKey::with('dealer')->where('key_hash', $hash)->first();

        if (! $apiKey) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        if ($apiKey->isExpired()) {
            return response()->json(['message' => 'API key has expired.'], 401);
        }

        $dealer = $apiKey->dealer;

        if (! $dealer || ! $dealer->is_active) {
            return response()->json(['message' => 'Dealer account is inactive.'], 403);
        }

        // Touch last_used_at without triggering model events
        $apiKey->timestamps = false;
        $apiKey->update(['last_used_at' => now()]);

        // Bind dealer into the container (same contract as TenantMiddleware)
        app()->instance('current_dealer', $dealer);

        return $next($request);
    }
}
