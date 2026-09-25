<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Exceptions\ValidationException as DomainValidationException;
use App\Models\Hotel;
use App\Models\RatePlan;
use App\Models\Room;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use Illuminate\Http\Request;

/**
 * Public read-only endpoint used by the marketing site to surface a hotel's
 * public details + live availability. No authentication required.
 */
class PublicHotelController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BookingService $bookings,
    ) {
    }

    /**
     * Public reservation request: an online guest creates a PENDING booking
     * that a hotel user must review and accept.
     */
    public function storeBooking(Request $request, string $slug)
    {
        $hotel = Hotel::query()->where('slug', $slug)->where('status', 'active')->first();

        if (! $hotel) {
            return response()->json(['message' => 'Hotel not found.', 'error' => 'NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'guest' => ['required', 'array'],
            'guest.first_name' => ['required', 'string', 'max:100'],
            'guest.last_name' => ['required', 'string', 'max:100'],
            'guest.email' => ['nullable', 'email', 'max:191'],
            'guest.phone' => ['required', 'string', 'max:40'],
            'guest.password' => ['nullable', 'string', 'min:8', 'max:191'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*' => ['required', 'integer', 'exists:rooms,id'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:50'],
            'children' => ['nullable', 'integer', 'min:0', 'max:50'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $booking = $this->bookings->create(
                $hotel,
                null,
                [
                    'guest' => $validated['guest'],
                    'check_in' => $validated['check_in'],
                    'check_out' => $validated['check_out'],
                    'rooms' => array_map(fn ($id) => ['room_id' => (int) $id], $validated['rooms']),
                    'adults' => $validated['adults'] ?? 1,
                    'children' => $validated['children'] ?? 0,
                    'source' => 'website',
                    'status' => 'pending',
                    'notes' => $validated['notes'] ?? null,
                ],
            );
        } catch (DomainValidationException|\App\Exceptions\BookingConflictException $e) {
            throw $e;
        }

        return response()->json(['data' => $booking], 201);
    }

    public function search(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'check_in' => ['nullable', 'date'],
            'check_out' => ['nullable', 'date', 'after:check_in'],
        ]);

        $query = Hotel::query()->where('status', 'active');

        if (! empty($validated['q'])) {
            $q = trim($validated['q']);
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('legal_name', 'like', "%{$q}%");
            });
        }

        if (! empty($validated['city'])) {
            $city = trim($validated['city']);
            $query->where('city', 'like', "%{$city}%");
        }

        $hotels = $query->orderBy('name')
            ->take(30)
            ->get(['id', 'name', 'slug', 'city', 'country', 'currency', 'phone', 'email', 'logo_path'])
            ->load('hotelImages');

        // Attach the uploaded gallery as a ready-to-render `images` list so the
        // public search cards can display an image scroller (logo only as fallback).
        $hotels->each(function (Hotel $hotel) {
            $hotel->setAttribute('images', $hotel->hotelImages
                ->sortBy('sort_order')
                ->map(fn ($img) => [
                    'id' => $img->id,
                    'sort_order' => $img->sort_order,
                    'image_url' => $img->image_url,
                ])
                ->values()
                ->all());
        });

        // When dates are supplied, keep only hotels with at least one room
        // free for the whole window and attach the list of bookable rooms
        // with their effective nightly rates.
        if (! empty($validated['check_in']) && ! empty($validated['check_out'])) {
            $hotels = $hotels->filter(fn (Hotel $hotel) => $this->availability->availableRooms(
                hotelId: $hotel->id,
                checkIn: $validated['check_in'],
                checkOut: $validated['check_out'],
            )->isNotEmpty())
                ->map(function (Hotel $hotel) use ($validated) {
                    $rooms = $this->availability->availableRooms(
                        hotelId: $hotel->id,
                        checkIn: $validated['check_in'],
                        checkOut: $validated['check_out'],
                    );

                    $planByType = RatePlan::query()
                        ->where('hotel_id', $hotel->id)
                        ->whereIn('room_type_id', $rooms->pluck('room_type_id')->unique()->all())
                        ->where('is_active', true)
                        ->get()
                        ->keyBy('room_type_id');

                    $promoByType = \App\Models\Promotion::runningByTypeForHotel($hotel->id);

                    $rooms = $rooms->map(function (Room $room) use ($planByType, $promoByType) {
                        $regular = $room->daily_rate_cents ?? $planByType->get($room->room_type_id)?->base_rate_cents ?? 0;

                        $room->setAttribute('original_rate_cents', (int) $regular);

                        $promo = $promoByType->get($room->room_type_id);
                        if ($promo) {
                            $room->setAttribute('promo_rate_cents', (int) $promo->promo_rate_cents);
                            $room->setAttribute('promo_title', $promo->title);
                            $room->setAttribute('rate_cents', (int) $promo->promo_rate_cents);
                        } else {
                            $room->setAttribute('rate_cents', (int) $regular);
                        }

                        return $room;
                    })->values();

                    $hotel->setAttribute('available_rooms', $rooms->load('roomType:id,name')->values());

                    return $hotel;
                })
                ->values();
        }

        return response()->json(['data' => $hotels]);
    }

    /**
     * Distinct cities that currently have at least one active hotel, so the
     * public search form can offer a truthful city dropdown.
     */
    public function cities()
    {
        return response()->json([
            'data' => Hotel::query()
                ->where('status', 'active')
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->distinct()
                ->orderBy('city')
                ->pluck('city')
                ->values()
                ->all(),
        ]);
    }

    /**
     * Promotions running for the current period, for the public homepage.
     * Each entry carries the hotel's name/city/slug, a lead image (first
     * gallery image, falling back to the logo), the average guest rating from
     * published reviews, and the discounted room type with crossed-out regular
     * rate vs the promo rate.
     */
    public function promotions()
    {
        $promos = \App\Models\Promotion::query()
            ->running()
            ->with([
                'roomType:id,name',
                'hotel:id,name,slug,city,country,currency,logo_path,status',
                'hotel.hotelImages:id,hotel_id,image_path,sort_order',
            ])
            ->orderByDesc('is_active')
            ->orderBy('created_at')
            ->get()
            ->filter(fn (\App\Models\Promotion $p) => $p->hotel?->status === 'active')
            ->values();

        return response()->json([
            'data' => $promos->map(function (\App\Models\Promotion $p) {
                $hotel = $p->hotel;
                $image = $hotel->hotelImages->sortBy('sort_order')->first();

                $ratingAvg = (float) $hotel->reviews()
                    ->published()
                    ->avg('rating');

                return [
                    'id' => $p->id,
                    'title' => $p->title,
                    'starts_on' => $p->starts_on->toDateString(),
                    'ends_on' => $p->ends_on->toDateString(),
                    'original_rate_cents' => $p->original_rate_cents,
                    'promo_rate_cents' => $p->promo_rate_cents,
                    'currency' => $p->currency,
                    'room_type' => $p->roomType ? ['id' => $p->roomType->id, 'name' => $p->roomType->name] : null,
                    'hotel' => [
                        'id' => $hotel->id,
                        'name' => $hotel->name,
                        'slug' => $hotel->slug,
                        'city' => $hotel->city,
                        'country' => $hotel->country,
                        'currency' => $hotel->currency,
                        'image_url' => $image?->image_url ?? $hotel->logo_url,
                        'rating' => $ratingAvg > 0 ? round($ratingAvg, 1) : null,
                        'rating_count' => $hotel->reviews()->published()->count(),
                    ],
                ];
            }),
        ]);
    }

    public function show(string $slug)
    {
        $hotel = Hotel::query()->where('slug', $slug)->where('status', 'active')->first();

        if (! $hotel) {
            return response()->json(['message' => 'Hotel not found.', 'error' => 'NOT_FOUND'], 404);
        }

        $roomTypes = $hotel->roomTypes()->where('is_active', true)->get();

        // Running promotions override the effective nightly rate for their room
        // type, and the crossed-out original rate is still exposed for the UI.
        $promoByType = \App\Models\Promotion::runningByTypeForHotel($hotel->id);

        // Effective nightly rate per room type = the same source the booking
        // engine prices from: the room's own daily rate wins, then the active
        // rate plan's base rate, then the type's anchor base rate.
        $roomsByType = Room::query()
            ->where('hotel_id', $hotel->id)
            ->whereIn('room_type_id', $roomTypes->pluck('id')->all())
            ->get()
            ->groupBy('room_type_id');

        $planByType = RatePlan::query()
            ->where('hotel_id', $hotel->id)
            ->whereIn('room_type_id', $roomTypes->pluck('id')->all())
            ->where('is_active', true)
            ->get()
            ->keyBy('room_type_id');

        $roomTypes = $roomTypes->map(function ($type) use ($roomsByType, $planByType, $promoByType) {
            $effective = $roomsByType->get($type->id)
                ?->map(fn (Room $room) => $room->daily_rate_cents
                    ?? $planByType->get($room->room_type_id)?->base_rate_cents
                    ?? 0)
                ->min();

            $regular = (int) ($effective
                ?? $planByType->get($type->id)?->base_rate_cents
                ?? $type->base_rate_cents);

            $type->setAttribute('nightly_rate_cents', $regular);

            $promo = $promoByType->get($type->id);
            if ($promo) {
                $type->setAttribute('original_rate_cents', $regular);
                $type->setAttribute('promo_rate_cents', (int) $promo->promo_rate_cents);
                $type->setAttribute('promo_title', $promo->title);
                $type->setAttribute('nightly_rate_cents', (int) $promo->promo_rate_cents);
            }

            return $type;
        });

        return response()->json([
            'data' => [
                'name' => $hotel->name,
                'city' => $hotel->city,
                'country' => $hotel->country,
                'currency' => $hotel->currency,
                'check_in_time' => $hotel->check_in_time->format('H:i'),
                'check_out_time' => $hotel->check_out_time->format('H:i'),
                'phone' => $hotel->phone,
                'email' => $hotel->email,
                'room_types' => $roomTypes->makeHidden(['created_at', 'updated_at']),
            ],
        ]);
    }

    public function availability(Request $request, string $slug)
    {
        $hotel = Hotel::query()->where('slug', $slug)->where('status', 'active')->first();

        if (! $hotel) {
            return response()->json(['message' => 'Hotel not found.', 'error' => 'NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'room_type_id' => ['nullable', 'integer'],
        ]);

        $rooms = $this->availability->availableRooms(
            hotelId: $hotel->id,
            checkIn: $validated['check_in'],
            checkOut: $validated['check_out'],
            roomTypeIds: $request->filled('room_type_id') ? [$validated['room_type_id']] : null,
        );

        // Effective nightly rate per room, same priority as BookingService
        // (room daily rate > active rate plan base), overridden by any running
        // promotion for the room's room type.
        $planByType = RatePlan::query()
            ->where('hotel_id', $hotel->id)
            ->whereIn('room_type_id', $rooms->pluck('room_type_id')->unique()->all())
            ->where('is_active', true)
            ->get()
            ->keyBy('room_type_id');

        $promoByType = \App\Models\Promotion::runningByTypeForHotel($hotel->id);

        $rooms = $rooms->map(function (Room $room) use ($planByType, $promoByType) {
            $regular = $room->daily_rate_cents ?? $planByType->get($room->room_type_id)?->base_rate_cents ?? 0;

            $room->setAttribute('original_rate_cents', (int) $regular);

            $promo = $promoByType->get($room->room_type_id);
            if ($promo) {
                $room->setAttribute('promo_rate_cents', (int) $promo->promo_rate_cents);
                $room->setAttribute('promo_title', $promo->title);
                $room->setAttribute('rate_cents', (int) $promo->promo_rate_cents);
            } else {
                $room->setAttribute('rate_cents', (int) $regular);
            }

            return $room;
        })->values();

        return response()->json([
            'data' => [
                'hotel' => $hotel->name,
                'currency' => $hotel->currency,
                'check_in' => $validated['check_in'],
                'check_out' => $validated['check_out'],
                'count' => $rooms->count(),
                'rooms' => $rooms->load('roomType:id,name')->values(),
            ],
        ]);
    }
}