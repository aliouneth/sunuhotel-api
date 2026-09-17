<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\HotelInvitation;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hotel team management: list members, create users, invite by email, change
 * roles and remove members — all scoped to the caller's tenant.
 */
class TeamController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('users.manage');

        $members = User::query()
            ->where('hotel_id', $request->user()->hotel_id)
            ->with('roles')
            ->get()
            ->map(function (User $user) {
                return $user->only(['id', 'name', 'email', 'locale', 'is_active', 'created_at'])
                    + ['roles' => $user->getRoleNames()];
            });

        return response()->json(['data' => $members]);
    }

    /**
     * Add a user directly (admin-created, with password).
     */
    public function store(Request $request)
    {
        $this->authorize('users.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10'],
            'role' => ['required', Rule::in(array_keys(Role::LABELS))],
        ]);

        /** @var Hotel $hotel */
        $hotel = $request->user()->hotel;

        $user = \DB::transaction(function () use ($validated, $hotel) {
            $user = User::create([
                'hotel_id' => $hotel->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            $this->assignRole($user, $validated['role']);

            return $user;
        });

        AuditLogger::critical($user, 'team.user_created', ['role' => $validated['role']]);

        return response()->json(['data' => $user->fresh('roles')], 201);
    }

    /**
     * Send an invitation email flow (token usable by existing/lobby user).
     */
    public function invite(Request $request)
    {
        $this->authorize('users.manage');

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::in(array_keys(Role::LABELS))],
        ]);

        $hotel = $request->user()->hotel;

        $invitation = HotelInvitation::create([
            'hotel_id' => $hotel->id,
            'email' => $validated['email'],
            'role' => $validated['role'],
            'token' => Str::random(64),
            'invited_by_name' => $request->user()->name,
            'expires_at' => now()->addDays(7),
        ]);

        // TODO(notifications): dispatch Mail\TeamInvitation after a Mailable is wired.
        AuditLogger::critical($invitation, 'team.invitation_sent', ['email' => $validated['email']]);

        return response()->json(['data' => $invitation], 201);
    }

    public function updateRole(Request $request, User $user)
    {
        $this->authorize('users.manage');

        if ($user->hotel_id !== $request->user()->hotel_id) {
            return response()->json(['message' => 'User not in this hotel.'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', 'string', Rule::in(array_keys(Role::LABELS))],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:10'],
        ]);

        if (array_key_exists('role', $validated) && $user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot change your own role.'], 422);
        }

        $update = collect($validated)->only(['name', 'email', 'is_active'])->all();
        if (isset($validated['password']) && $validated['password'] !== null && $validated['password'] !== '') {
            $update['password'] = $validated['password'];
        }
        if ($update !== []) {
            $user->update($update);
        }

        if (isset($validated['role'])) {
            $this->assignRole($user, $validated['role']);
        }

        AuditLogger::critical($user, 'team.user_updated', [
            'roles' => $user->getRoleNames(),
        ]);

        return response()->json([
            'data' => $user->fresh()->only(['id', 'name', 'email', 'locale', 'is_active', 'created_at'])
                + ['roles' => $user->getRoleNames()],
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        $this->authorize('users.manage');

        if ($user->hotel_id !== $request->user()->hotel_id) {
            return response()->json(['message' => 'User not in this hotel.'], 404);
        }

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot remove yourself.'], 422);
        }

        $user->update(['is_active' => false]);
        $user->tokens()->delete();

        AuditLogger::critical($user, 'team.user_removed');

        return response()->json(['message' => 'User deactivated.']);
    }

    public function syncPermissionsTeam(User $user): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->hotel_id);
    }

    private function assignRole(User $user, string $role): void
    {
        // Ensure the joint team context so model_has_roles records hotel_id.
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->hotel_id);
        $user->syncRoles([Role::query()->where('name', $role)->first()]);
    }
}