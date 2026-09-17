<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Spatie\Permission\PermissionRegistrar;

/**
 * Public authentication. Registration also provisions the tenant (hotel) and
 * assigns the founder the hotel-scoped `owner` role.
 */
class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
            'locale' => ['sometimes', 'in:fr,en'],
            'hotel' => ['required', 'array'],
            'hotel.name' => ['required', 'string', 'max:120'],
            'hotel.slug' => ['sometimes', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:hotels,slug'],
            'hotel.country' => ['sometimes', 'string', 'max:2'],
            'hotel.currency' => ['sometimes', 'string', 'size:3'],
            'hotel.timezone' => ['sometimes', 'string', 'max:64'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $locale = $validated['locale'] ?? 'en';

        return \DB::transaction(function () use ($validated, $locale, $request) {
            $hotel = Hotel::create([
                'uuid' => (string) Str::uuid(),
                'slug' => $validated['hotel']['slug'] ?? Str::slug($validated['hotel']['name']).'-'.Str::lower(Str::random(4)),
                'name' => $validated['hotel']['name'],
                'country' => $validated['hotel']['country'] ?? null,
                'currency' => $validated['hotel']['currency'] ?? 'USD',
                'timezone' => $validated['hotel']['timezone'] ?? 'UTC',
                'settings' => ['locale' => $locale],
                // New tenants must be reviewed and activated by a platform admin.
                'status' => 'pending',
            ]);

            if ($request->hasFile('logo')) {
                $hotel->setLogo($request->file('logo'));
            }

            // Bootstrap the catalogue (room types + expense types) from the
            // "Sunuhotel" sample hotel so new tenants start ready to operate.
            if ($sample = Hotel::where('slug', Hotel::SAMPLE_HOTEL_SLUG)->first()) {
                $hotel->importSetupFrom($sample);
            }

            $user = User::create([
                'hotel_id' => $hotel->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'locale' => $locale,
            ]);

            // Materialise global permissions + role definitions once, then
            // assign the founder the `owner` role inside this hotel's team context.
            Role::seedPolicies();
            app(PermissionRegistrar::class)->setPermissionsTeamId($hotel->id);
            $owner = Role::query()->where('name', 'owner')->first();
            $user->assignRole($owner);

            AuditLogger::log('tenant.created', $hotel, null, ['by' => $user->email]);

            $token = $user->createToken('auth')->plainTextToken;

            return response()->json([
                'message' => 'Hotel and account created.',
                'token' => $token,
                'user' => $this->present($user->fresh(['hotel'])),
            ], 201);
        });
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password) || ! $user->is_active) {
            return response()->json([
                'message' => 'Invalid credentials.',
                'error' => 'INVALID_CREDENTIALS',
            ], 401);
        }

        $token = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->present($user->fresh(['hotel'])),
        ]);
    }

    /**
     * @authenticated
     */
    public function me(Request $request)
    {
        return response()->json(['data' => $this->present($request->user()->load(['hotel']))]);
    }

    /**
     * Serialise a user with the roles and permission names resolved inside the
     * right team context, so the client can hide what the user cannot access.
     */
    private function present(User $user): array
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->hotel_id);

        $data = $user->toArray();
        $data['roles'] = $user->roles
            ->map(fn ($role) => ['id' => $role->id, 'name' => $role->name])
            ->values()
            ->all();
        $data['permissions'] = $user->getAllPermissions()->pluck('name')->values()->all();

        return $data;
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        $status = Password::broker()->sendResetLink($validated);

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => 'Reset link sent.'])
            : response()->json(['message' => __('passwords.sent')], 400);
    }
}