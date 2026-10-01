<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform staff administration.
 *
 * A "platform user" is an account that is NOT attached to a hotel
 * (`users.hotel_id IS NULL`) - i.e. the people who manage the platform itself
 * (platform administrators), exactly the accounts that pass
 * EnsurePlatformAdmin. Hotel staff accounts are deliberately out of scope here
 * and must not be reachable through this surface.
 */
class PlatformUserController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'in:active,inactive,all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $q = trim((string) ($validated['q'] ?? ''));
        $status = (string) ($validated['status'] ?? 'all');

        $page = User::query()
            ->whereNull('hotel_id')
            ->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            }))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $rows = collect($page->items())
            ->map(fn (User $user) => $this->presentUser($user))
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * Create a new platform user (hotel_id stays NULL => platform staff).
     */
    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
            'locale' => ['sometimes', 'in:fr,en'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user = new User();
        $user->name = $validated['name'];
        $user->email = $validated['email'];
        // User casts `password` to "hashed", so the plain value is hashed on save.
        $user->password = $validated['password'];
        $user->locale = $validated['locale'] ?? 'en';
        $user->is_active = (bool) ($validated['is_active'] ?? true);
        // Explicitly platform-level: never attach to a hotel.
        $user->hotel_id = null;
        $user->save();

        AuditLogger::critical($user, 'platform.user_created', [
            'by' => $request->user()?->email,
            'email' => $user->email,
        ]);

        return response()->json([
            'message' => 'Platform user created.',
            'data' => $this->presentUser($user),
        ], 201);
    }

    public function update(Request $request, int $user): Response
    {
        $target = User::query()->whereNull('hotel_id')->find($user);

        if (! $target) {
            return response()->json(['message' => 'Platform user not found.'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'locale' => ['sometimes', 'string', 'in:fr,en'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Never allow an administrator to lock themselves out.
        if (array_key_exists('is_active', $validated)
            && ! $validated['is_active']
            && $target->id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot deactivate your own account.',
                'errors' => ['is_active' => ['You cannot deactivate your own account.']],
            ], 422);
        }

        if (array_key_exists('name', $validated)) {
            $target->name = $validated['name'];
        }

        if (array_key_exists('locale', $validated)) {
            $target->locale = $validated['locale'];
        }

        if (array_key_exists('is_active', $validated)) {
            $target->is_active = (bool) $validated['is_active'];
        }

        $target->save();

        AuditLogger::critical($target, 'platform.user_updated', [
            'by' => $request->user()?->email,
            'fields' => array_keys($validated),
        ]);

        return response()->json(['data' => $this->presentUser($target)]);
    }

    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'is_active' => (bool) $user->is_active,
            'created_at' => $user->created_at?->toISOString(),
            'last_login' => property_exists($user, 'last_login_at')
                ? $user->last_login_at?->toISOString()
                : null,
        ];
    }
}
