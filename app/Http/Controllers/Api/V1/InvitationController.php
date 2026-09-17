<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HotelInvitation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Spatie\Permission\PermissionRegistrar;

/**
 * Accepts a team invitation. Two paths:
 *   - the email already has an account (any hotel): join the hotel with the
 *     invited role,
 *   - otherwise the invitee provides a password and a user is provisioned.
 */
class InvitationController extends Controller
{
    public function show(string $token)
    {
        $invitation = HotelInvitation::query()->where('token', $token)->first();

        if (! $invitation || ! $invitation->isValid()) {
            return response()->json(['message' => 'Invitation is invalid or has expired.', 'error' => 'INVALID_INVITATION'], 404);
        }

        return response()->json([
            'data' => [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'hotel' => $invitation->hotel()->first(['id', 'slug', 'name']),
            ],
        ]);
    }

    public function accept(Request $request, string $token)
    {
        $invitation = HotelInvitation::query()->where('token', $token)->first();

        if (! $invitation || ! $invitation->isValid()) {
            return response()->json(['message' => 'Invitation is invalid or has expired.', 'error' => 'INVALID_INVITATION'], 422);
        }

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['sometimes', 'string', 'max:120'],
            'password' => ['sometimes', PasswordRule::min(10)->letters()->numbers()],
        ]);

        if (strtolower($validated['email']) !== strtolower($invitation->email)) {
            return response()->json(['message' => 'Invitation is bound to a different email address.'], 422);
        }

        $user = User::where('email', $invitation->email)->first();

        if (! $user) {
            $user = User::create([
                'hotel_id' => $invitation->hotel_id,
                'name' => $validated['name'] ?? strstr($invitation->email, '@', true),
                'email' => $invitation->email,
                'password' => $validated['password'] ?? (string) Hash::make(bin2hex(random_bytes(8))),
            ]);
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($invitation->hotel_id);
        $role = \App\Models\Role::query()->where('name', $invitation->role)->first();
        $user->syncRoles([$role]);

        // Rebind the user to this hotel if they were a platform account.
        if ($user->hotel_id === null) {
            $user->update(['hotel_id' => $invitation->hotel_id]);
        }

        $invitation->update([
            'accepted_at' => now(),
            'token' => null,
        ]);

        AuditLogger::critical($user, 'team.invitation_accepted', ['role' => $invitation->role]);

        return response()->json(['message' => 'Welcome to the team.', 'data' => $user->fresh('hotel')]);
    }
}