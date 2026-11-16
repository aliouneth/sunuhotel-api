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
            'hotel.legal_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'hotel.address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hotel.city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'hotel.country' => ['sometimes', 'string', 'max:2'],
            'hotel.currency' => ['sometimes', 'string', 'size:3'],
            'hotel.phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'hotel.phone_2' => ['sometimes', 'nullable', 'string', 'max:40'],
            'hotel.email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'hotel.website' => ['sometimes', 'nullable', 'url', 'max:191'],
            'hotel.description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'hotel.other_services' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'hotel.tax_rate' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'hotel.stars' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'hotel.check_in_time' => ['sometimes', 'nullable', 'string', 'date_format:H:i'],
            'hotel.check_out_time' => ['sometimes', 'nullable', 'string', 'date_format:H:i'],
            'hotel.timezone' => ['sometimes', 'nullable', 'string', 'max:64', 'timezone'],
            'hotel.locale' => ['sometimes', 'nullable', 'string', 'in:fr,en'],
            'hotel.has_restaurant' => ['sometimes', 'boolean'],
            'hotel.has_spa' => ['sometimes', 'boolean'],
            'hotel.has_leisure' => ['sometimes', 'boolean'],
            'hotel.phone' => ['sometimes', 'string', 'max:32'],
            'hotel.timezone' => ['sometimes', 'string', 'max:64'],
            'hotel.has_restaurant' => ['sometimes', 'boolean'],
            'hotel.has_spa' => ['sometimes', 'boolean'],
            'hotel.has_leisure' => ['sometimes', 'boolean'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $locale = $validated['locale'] ?? 'en';

        return \DB::transaction(function () use ($validated, $locale, $request) {
            $hotel = Hotel::create([
                'uuid' => (string) Str::uuid(),
                'slug' => Str::slug($validated['hotel']['name']).'-'.Str::lower(Str::random(4)),
                'name' => $validated['hotel']['name'],
                'legal_name' => $validated['hotel']['legal_name'] ?? null,
                'address' => $validated['hotel']['address'] ?? null,
                'city' => $validated['hotel']['city'] ?? null,
                'country' => $validated['hotel']['country'] ?? null,
                'phone' => $validated['hotel']['phone'] ?? null,
                'phone_2' => $validated['hotel']['phone_2'] ?? null,
                'email' => $validated['hotel']['email'] ?? null,
                'website' => $validated['hotel']['website'] ?? null,
                'description' => $validated['hotel']['description'] ?? null,
                'other_services' => $validated['hotel']['other_services'] ?? null,
                'currency' => $validated['hotel']['currency'] ?? 'USD',
                'timezone' => $validated['hotel']['timezone'] ?? 'UTC',
                'tax_rate' => $validated['hotel']['tax_rate'] ?? 0,
                'stars' => $validated['hotel']['stars'] ?? null,
                'check_in_time' => $validated['hotel']['check_in_time'] ?? '15:00',
                'check_out_time' => $validated['hotel']['check_out_time'] ?? '11:00',
                'has_restaurant' => (bool) ($validated['hotel']['has_restaurant'] ?? false),
                'has_spa' => (bool) ($validated['hotel']['has_spa'] ?? false),
                'has_leisure' => (bool) ($validated['hotel']['has_leisure'] ?? false),
                'settings' => ['locale' => $validated['hotel']['locale'] ?? $locale],
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

            // Track the founder as the hotel's creator.
            $hotel->update(['created_by' => $user->id]);

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
