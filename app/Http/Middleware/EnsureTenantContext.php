<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\HotelContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves and pins the tenant context (hotel_id) for the whole request.
 *
 * Order of resolution:
 *   1. authenticated user's hotel_id (typical Admin panel flow)
 *   2. explicit "X-Hotel: <id|slug>" header (impersonation / multi-hotel users)
 *
 * Platform admins (hotel_id = null) are intentionally rejected from tenant
 * routes unless they pass the X-Hotel header.
 *
 * Applying this middleware to the whole api/v1 group guarantees that every
 * tenant-owned query is scoped by the HotelScope driven by HotelContext.
 */
class EnsureTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        $explicit = $request->header('X-Hotel');

        if ($explicit !== null) {
            $hotel = \App\Models\Hotel::query()
                ->withoutGlobalScopes()
                ->where(function ($q) use ($explicit) {
                    $q->where('id', $explicit)->orWhere('slug', $explicit);
                })
                ->first();

            // Only allow explicit switch when the user already belongs or is platform admin.
            if (! $hotel || ! ($user && ($user->isPlatformAdmin() || $user->hotel_id === $hotel->id))) {
                return response()->json([
                    'message' => 'Invalid hotel context.',
                    'error' => 'TENANT_ACCESS_DENIED',
                ], 403);
            }

            // Platform admins impersonate as tenant owner inside the chosen team.
            if ($user->isPlatformAdmin()) {
                $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
                $registrar->setPermissionsTeamId($hotel->id);
                $user->syncRoles([\App\Models\Role::query()->where('name', 'owner')->first()]);
            } else {
                app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($user->hotel_id);
            }

            HotelContext::set($hotel);

            return $next($request);
        }

        if ($user) {
            if ($user->hotel_id === null) {
                return response()->json([
                    'message' => 'You do not belong to a hotel. Provide an X-Hotel header or contact support.',
                    'error' => 'NO_TENANT',
                ], 403);
            }

            // Spark the spatie team context for RBAC on the user's tenant.
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($user->hotel_id);

            $hotel = \App\Models\Hotel::query()->withoutGlobalScopes()->find($user->hotel_id);

            // Tenants must be reviewed + activated by the platform before use.
            if ($hotel && $hotel->status !== 'active') {
                $reason = $hotel->status === 'rejected'
                    ? 'Your hotel registration was not approved. Please contact Sunuhotel support.'
                    : 'Your hotel is being reviewed by Sunuhotel. You will be able to use it once it is activated.';

                return response()->json([
                    'message' => $reason,
                    'error' => 'HOTEL_NOT_ACTIVE',
                ], 403);
            }

            HotelContext::set($hotel ?? $user->hotel_id);
        }

        return $next($request);
    }
}