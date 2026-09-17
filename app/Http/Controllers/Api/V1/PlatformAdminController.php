<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\PlatformSettings;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\HotelScope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;

/**
 * Platform administration: tenant review/activation and corporate info.
 *
 * Both accessors are behind the `platform.admin` middleware, so every action
 * here runs only for hotel-less platform administrators.
 */
class PlatformAdminController extends Controller
{
    public function summary()
    {
        $byStatus = Hotel::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = array_fill_keys(Hotel::STATUSES, 0);
        foreach ($byStatus as $status => $total) {
            if (isset($counts[$status])) {
                $counts[$status] = (int) $total;
            }
        }

        return response()->json([
            'data' => [
                'hotels' => array_sum($counts),
                'users' => (int) \App\Models\User::query()->whereNotNull('hotel_id')->count(),
                'pending' => $counts['pending'],
                'by_status' => $counts,
            ],
        ]);
    }

    public function hotels(Request $request)
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,active,suspended,rejected,trial'],
            'search' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $hotels = Hotel::query()
            ->withCount(['users', 'rooms', 'bookings'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['search'] ?? null, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhereHas('users', fn ($q) => $q->where('email', 'like', "%{$search}%"));
                });
            })
            ->orderByRaw("case status when 'pending' then 0 else 1 end")
            ->orderBy('id', 'desc')
            ->paginate($validated['per_page'] ?? 25)
            ->through(fn (Hotel $hotel) => $this->present($hotel));

        return response()->json(['data' => $hotels]);
    }

    public function show(Hotel $hotel)
    {
        return response()->json(['data' => $this->present($hotel)]);
    }

    public function update(Request $request, Hotel $hotel)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'slug' => [
                'sometimes', 'nullable', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('hotels', 'slug')->ignore($hotel->id),
            ],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'nullable', 'string', 'max:2'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'website' => ['sometimes', 'nullable', 'url', 'max:191'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
            'status' => ['sometimes', 'string', Rule::in(Hotel::STATUSES)],
            'locale' => ['sometimes', 'string', 'in:fr,en'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $update = collect($validated)->only([
            'name', 'legal_name', 'slug', 'address', 'city', 'country',
            'phone', 'email', 'website', 'currency', 'timezone', 'status',
        ])->all();

        if (($validated['slug'] ?? null) === null) {
            unset($update['slug']);
        }

        if (isset($validated['slug']) && trim($validated['slug']) === '') {
            unset($update['slug']);
        }

        if (isset($validated['locale'])) {
            $settings = array_merge($hotel->settings ?? [], ['locale' => $validated['locale']]);
            $update['settings'] = $settings;
        }

        $hotel->update($update);

        if ($request->hasFile('logo')) {
            $hotel->setLogo($request->file('logo'));
        }

        AuditLogger::critical($hotel, 'tenant.updated', [
            'by' => auth('sanctum')->user()->email,
            'slug' => $hotel->slug,
        ]);

        return response()->json(['data' => $this->present($hotel->fresh())]);
    }

    public function approve(Hotel $hotel)
    {
        if ($hotel->status !== 'active') {
            $hotel->update(['status' => 'active']);
            AuditLogger::critical($hotel, 'tenant.approved', ['by' => auth('sanctum')->user()->email]);
        }

        return response()->json(['data' => $this->present($hotel)]);
    }

    public function reject(Hotel $hotel)
    {
        $hotel->update(['status' => 'rejected']);
        AuditLogger::critical($hotel, 'tenant.rejected', ['by' => auth('sanctum')->user()->email]);

        return response()->json(['data' => $this->present($hotel)]);
    }

    public function suspend(Hotel $hotel)
    {
        $hotel->update(['status' => 'suspended']);
        AuditLogger::critical($hotel, 'tenant.suspended', ['by' => auth('sanctum')->user()->email]);

        return response()->json(['data' => $this->present($hotel)]);
    }

    public function settings()
    {
        return response()->json(['data' => $this->presentSettings(PlatformSettings::current())]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'company_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'nullable', 'string', 'max:2'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'website' => ['sometimes', 'nullable', 'url', 'max:191'],
            'hours' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $settings = PlatformSettings::current();
        $settings->fill($validated);
        $settings->id = PlatformSettings::SINGLE_ROW_ID;
        $settings->save();

        AuditLogger::log('platform.settings_updated', null, $settings->id, ['by' => auth('sanctum')->user()->email]);

        return response()->json(['data' => $this->presentSettings($settings)]);
    }

    /**
     * Public, unauthenticated corporate contact info (registration screen).
     */
    public function support()
    {
        return response()->json(['data' => $this->presentSettings(PlatformSettings::current())]);
    }

    public function rooms(Request $request, Hotel $hotel)
    {
        $rooms = $hotel->rooms()
            ->with(['roomType:id,name', 'amenities:id,name,icon'])
            ->orderBy('floor')
            ->orderBy('room_number')
            ->get();

        return response()->json(['data' => $rooms]);
    }

    public function roomTypes(Request $request, Hotel $hotel)
    {
        $types = $hotel->roomTypes()
            ->withCount('rooms')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $types]);
    }

    public function storeRoomType(Request $request, Hotel $hotel)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'base_capacity' => ['nullable', 'integer', 'min:1', 'max:50'],
            'max_capacity' => ['nullable', 'integer', 'min:1', 'max:200'],
            'base_rate_cents' => ['nullable', 'integer', 'min:0'],
            'features' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $roomType = $hotel->roomTypes()->create([
            'slug' => \Illuminate\Support\Str::slug($validated['name']),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'base_capacity' => $validated['base_capacity'] ?? 2,
            'max_capacity' => $validated['max_capacity'] ?? (int) ($validated['base_capacity'] ?? 2) + 2,
            'base_rate_cents' => $validated['base_rate_cents'] ?? 0,
            'features' => $validated['features'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        AuditLogger::critical($roomType, 'room_type.created', [
            'by' => auth('sanctum')->user()->email,
        ]);

        return response()->json(['data' => $roomType->fresh()], 201);
    }

    /**
     * Copy room types from another hotel into this one. Existing names are
     * skipped so the operation is safe to run repeatedly.
     */
    public function importRoomTypes(Request $request, Hotel $hotel)
    {
        $validated = $request->validate([
            'source_hotel_id' => ['required', 'integer', 'exists:hotels,id'],
        ]);

        $sourceId = (int) $validated['source_hotel_id'];
        abort_if($sourceId === $hotel->id, 422, 'The source hotel must be different from the target hotel.');

        $source = Hotel::findOrFail($sourceId);

        $existing = $hotel->roomTypes()
            ->withoutGlobalScope(HotelScope::class)
            ->pluck('name')
            ->map(fn ($name) => mb_strtolower($name))
            ->all();

        $imported = 0;
        $skipped = 0;

        foreach ($source->roomTypes()->withoutGlobalScope(HotelScope::class)->orderBy('name')->get() as $type) {
            if (in_array(mb_strtolower($type->name), $existing, true)) {
                $skipped++;
                continue;
            }

            $hotel->roomTypes()->create([
                'slug' => $type->slug,
                'name' => $type->name,
                'description' => $type->description,
                'base_capacity' => $type->base_capacity,
                'max_capacity' => $type->max_capacity,
                'base_rate_cents' => $type->base_rate_cents,
                'features' => $type->features,
                'is_active' => $type->is_active,
            ]);

            $existing[] = mb_strtolower($type->name);
            $imported++;
        }

        AuditLogger::critical($hotel, 'room_types.imported', [
            'by' => auth('sanctum')->user()->email,
            'source_hotel_id' => $sourceId,
            'imported' => $imported,
            'skipped' => $skipped,
        ]);

        return response()->json(['data' => ['imported' => $imported, 'skipped' => $skipped]]);
    }

    public function updateRoomType(Request $request, Hotel $hotel, RoomType $roomType)
    {
        abort_unless($roomType->hotel_id === $hotel->id, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'base_capacity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'max_capacity' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'base_rate_cents' => ['sometimes', 'integer', 'min:0'],
            'features' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = \Illuminate\Support\Str::slug($validated['name']);
        }

        $roomType->update($validated);
        AuditLogger::critical($roomType, 'room_type.updated', [
            'by' => auth('sanctum')->user()->email,
        ]);

        return response()->json(['data' => $roomType->fresh()]);
    }

    public function destroyRoomType(Request $request, Hotel $hotel, RoomType $roomType)
    {
        abort_unless($roomType->hotel_id === $hotel->id, 404);

        if ($roomType->rooms()->exists()) {
            return response()->json([
                'message' => 'Room type has rooms; reassign them first.',
                'error' => 'ROOM_TYPE_IN_USE',
            ], 422);
        }

        $roomType->delete();
        AuditLogger::critical($roomType, 'room_type.deleted', [
            'by' => auth('sanctum')->user()->email,
        ]);

        return response()->json(['message' => 'Deleted.']);
    }

    public function users(Request $request, Hotel $hotel)
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($hotel->id);

        $users = $hotel->users()
            ->orderBy('id')
            ->get()
            ->map(fn (User $user) => $this->presentUser($user));

        return response()->json(['data' => $users]);
    }

    public function storeUser(Request $request, Hotel $hotel)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10'],
            'role' => ['required', Rule::in(array_keys(Role::LABELS))],
            'is_active' => ['sometimes', 'boolean'],
            'locale' => ['sometimes', 'string', 'in:fr,en'],
        ]);

        $user = \DB::transaction(function () use ($validated, $hotel) {
            $user = $hotel->users()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'is_active' => $validated['is_active'] ?? true,
                'locale' => $validated['locale'] ?? ($hotel->settings['locale'] ?? 'en'),
            ]);

            $this->syncRole($hotel, $user, $validated['role']);

            return $user;
        });

        AuditLogger::critical($user, 'tenant.user_created', [
            'by' => auth('sanctum')->user()->email,
            'role' => $validated['role'],
        ]);

        return response()->json(['data' => $this->presentUser($user->fresh())], 201);
    }

    public function updateUser(Request $request, Hotel $hotel, User $user)
    {
        abort_unless($user->hotel_id === $hotel->id, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', 'string', Rule::in(array_keys(Role::LABELS))],
            'is_active' => ['sometimes', 'boolean'],
            'locale' => ['sometimes', 'string', 'in:fr,en'],
            'password' => ['sometimes', 'string', 'min:10'],
        ]);

        $update = collect($validated)->only(['name', 'email', 'is_active', 'locale', 'password'])->all();
        if ($update !== []) {
            $user->update($update);
        }

        if (isset($validated['role'])) {
            $this->syncRole($hotel, $user, $validated['role']);
        }

        AuditLogger::critical($user, 'tenant.user_updated', [
            'by' => auth('sanctum')->user()->email,
        ]);

        return response()->json(['data' => $this->presentUser($user->fresh())]);
    }

    private function syncRole(Hotel $hotel, User $user, string $role): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($hotel->id);
        $user->syncRoles([Role::query()->where('name', $role)->first()]);
    }

    public function storeRoom(Request $request, Hotel $hotel)
    {
        $validated = $request->validate([
            'room_number' => ['required', 'string', 'max:20'],
            'floor' => ['nullable', 'integer', 'min:0', 'max:999'],
            'room_type_id' => ['required', 'exists:room_types,id'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'daily_rate_cents' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(Room::STATUSES)],
            'keycard_code' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string'],
        ]);

        $roomType = $hotel->roomTypes()->findOrFail($validated['room_type_id']);

        $room = $hotel->rooms()->create([
            'room_number' => $validated['room_number'],
            'floor' => $validated['floor'] ?? 0,
            'room_type_id' => $roomType->id,
            'capacity' => $validated['capacity'] ?? $roomType->base_capacity,
            'daily_rate_cents' => $validated['daily_rate_cents'] ?? null,
            'status' => $validated['status'] ?? 'available',
            'keycard_code' => $validated['keycard_code'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        AuditLogger::critical($room, 'room.created', ['by' => auth('sanctum')->user()->email]);

        return response()->json(['data' => $room->load(['roomType:id,name', 'amenities:id,name,icon'])], 201);
    }

    public function updateRoom(Request $request, Hotel $hotel, Room $room)
    {
        abort_unless($room->hotel_id === $hotel->id, 404);

        $validated = $request->validate([
            'room_number' => ['sometimes', 'string', 'max:20'],
            'floor' => ['nullable', 'integer', 'min:0', 'max:999'],
            'room_type_id' => ['sometimes', 'exists:room_types,id'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'daily_rate_cents' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(Room::STATUSES)],
            'keycard_code' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string'],
        ]);

        if (! empty($validated['room_type_id'])) {
            $hotel->roomTypes()->findOrFail($validated['room_type_id']);
        }

        $room->update($validated);
        AuditLogger::critical($room, 'room.updated', ['by' => auth('sanctum')->user()->email]);

        return response()->json(['data' => $room->fresh(['roomType:id,name', 'amenities:id,name,icon'])]);
    }

    public function destroyRoom(Request $request, Hotel $hotel, Room $room)
    {
        abort_unless($room->hotel_id === $hotel->id, 404);

        if ($room->bookingRooms()->where('active', true)->exists()) {
            return response()->json([
                'message' => 'Room has active bookings.',
                'error' => 'ROOM_HAS_BOOKINGS',
            ], 422);
        }

        AuditLogger::critical($room, 'room.deleted', ['by' => auth('sanctum')->user()->email]);
        $room->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    private function present(Hotel $hotel): array
    {
        $owner = $hotel->users()->orderBy('id')->first();

        return [
            'id' => $hotel->id,
            'slug' => $hotel->slug,
            'name' => $hotel->name,
            'legal_name' => $hotel->legal_name,
            'address' => $hotel->address,
            'city' => $hotel->city,
            'country' => $hotel->country,
            'phone' => $hotel->phone,
            'email' => $hotel->email,
            'website' => $hotel->website,
            'currency' => $hotel->currency,
            'timezone' => $hotel->timezone,
            'status' => $hotel->status,
            'logo_url' => $hotel->logo_url,
            'locale' => $hotel->settings['locale'] ?? null,
            'created_at' => $hotel->created_at?->toISOString(),
            'users_count' => $hotel->users_count ?? $hotel->users()->count(),
            'rooms_count' => $hotel->rooms_count ?? $hotel->rooms()->count(),
            'bookings_count' => $hotel->bookings_count ?? $hotel->bookings()->count(),
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
            ] : null,
        ];
    }

    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'is_active' => (bool) $user->is_active,
            'roles' => $user->getRoleNames(),
            'created_at' => $user->created_at?->toISOString(),
        ];
    }

    private function presentSettings(PlatformSettings $settings): array
    {
        $values = PlatformSettings::defaults();
        foreach (array_keys($values) as $key) {
            if ($settings->getAttribute($key) !== null) {
                $values[$key] = $settings->getAttribute($key);
            }
        }

        return $values;
    }
}