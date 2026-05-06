<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure the authenticated user has one of the required roles.
 *
 * Usage in routes:
 *   ->middleware('role:dealer_admin,dealer_staff')
 */
class EnsureRole
{
    /**
     * @param  Closure(Request):Response  $next
     * @param  string  ...$roles  One or more allowed role names
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
