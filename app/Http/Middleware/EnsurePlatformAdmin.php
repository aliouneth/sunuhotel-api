<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the platform administration surface.
 *
 * Only users who are NOT attached to a hotel (hotel_id = NULL) may manage
 * tenants. Tenant members are always denied.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (! $user || ! $user->isPlatformAdmin()) {
            return response()->json([
                'message' => 'Platform administrator access required.',
                'error' => 'PLATFORM_ADMIN_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}