<?php

namespace App\Http\Middleware;

use App\Models\Dealer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current dealer from the request and binds it into the IoC container.
 *
 * Resolution order:
 *  1. Subdomain  (e.g. demo.autoclose.test)
 *  2. X-Dealer-Domain header (custom white-label domain)
 *  3. dealer_id claim on the authenticated user's token
 */
class TenantMiddleware
{
    /**
     * @param  Closure(Request):Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $dealer = $this->resolveFromSubdomain($request)
            ?? $this->resolveFromHeader($request)
            ?? $this->resolveFromUser($request)
            ?? $this->resolveFromVehicle($request)
            ?? $this->resolveFromDeal($request);

        if (! $dealer) {
            return response()->json(['message' => 'Dealer not found.'], 404);
        }

        if (! $dealer->is_active) {
            return response()->json(['message' => 'This dealer account is inactive.'], 403);
        }

        app()->instance('current_dealer', $dealer);

        return $next($request);
    }

    private function resolveFromSubdomain(Request $request): ?Dealer
    {
        $host = $request->getHost();
        // Strip port and extract first label
        $subdomain = explode('.', $host)[0];

        if ($subdomain && ! in_array($subdomain, ['www', 'api', 'localhost'], true)) {
            return Dealer::where('subdomain', $subdomain)->first();
        }

        return null;
    }

    private function resolveFromHeader(Request $request): ?Dealer
    {
        // Support custom domain header (white-label)
        $domain = $request->header('X-Dealer-Domain');
        if ($domain) {
            return Dealer::where('custom_domain', $domain)->first();
        }

        // Support slug/subdomain header (web & mobile clients)
        $slug = $request->header('X-Dealer-Slug');
        if ($slug) {
            return Dealer::where('subdomain', $slug)->first()
                ?? Dealer::where('slug', $slug)->first();
        }

        return null;
    }

    private function resolveFromUser(Request $request): ?Dealer
    {
        $user = $request->user();

        if ($user && $user->dealer_id) {
            return Dealer::find($user->dealer_id);
        }

        return null;
    }

    /**
     * Buyers starting a deal don't carry a dealer context — resolve from the
     * vehicle they're purchasing instead.
     */
    private function resolveFromVehicle(Request $request): ?Dealer
    {
        $vehicleId = $request->input('vehicle_id');

        if (! $vehicleId) {
            return null;
        }

        $vehicle = \App\Models\Vehicle::select('dealer_id')->find((int) $vehicleId);

        return $vehicle ? Dealer::find($vehicle->dealer_id) : null;
    }

    /**
     * Buyers updating or accessing sub-resources on an existing deal don't carry
     * a dealer context — resolve from the deal they're working on instead.
     *
     * Supports both route parameter {deal} and a `deal_id` query/body param
     * (used when there is no deal route param, e.g. GET /fi-products?deal_id=…).
     */
    private function resolveFromDeal(Request $request): ?Dealer
    {
        $dealId = $request->route('deal')
            ?? $request->input('deal_id');

        if (! $dealId) {
            return null;
        }

        $deal = \App\Models\Deal::select('dealer_id')->find((int) $dealId);

        return $deal ? Dealer::find($deal->dealer_id) : null;
    }
}
