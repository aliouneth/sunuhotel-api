<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingConflictException;
use App\Exceptions\ValidationException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\PaymentService;
use Illuminate\Http\Request;

/**
 * Booking lifecycle: CRUD + check-in / check-out / cancel / no-show.
 * All workflow mutations delegate to BookingService which owns the
 * transactional guarantees and the audit trail.
 */
class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly PaymentService $payments,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('bookings.view');

        $bookings = Booking::query()
            ->with(['guest', 'rooms.room', 'payments.receiver'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('check_in', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('check_in', '<=', $request->string('to')))
            ->when($request->filled('guest_id'), fn ($q) => $q->where('guest_id', $request->integer('guest_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('booking_number', 'like', '%'.$request->string('search').'%'))
            ->orderByDesc('check_in')
            ->paginate(30);

        return response()->json(['data' => $bookings]);
    }

    public function store(Request $request)
    {
        $this->authorize('bookings.manage');

        $validated = $request->validate([
            'guest_id' => ['nullable', 'integer', 'exists:guests,id'],
            'guest' => ['required_without:guest_id', 'array'],
            'guest.first_name' => ['required_with:guest', 'string', 'max:100'],
            'guest.last_name' => ['required_with:guest', 'string', 'max:100'],
            'guest.email' => ['nullable', 'email', 'max:191'],
            'guest.phone' => ['nullable', 'string', 'max:40'],
            'guest.nationality' => ['nullable', 'string', 'size:3'],
            'guest.notes' => ['nullable', 'string', 'max:500'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*.room_id' => ['required', 'exists:rooms,id'],
            'rooms.*.rate_plan_id' => ['nullable', 'exists:rate_plans,id'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:50'],
            'children' => ['nullable', 'integer', 'min:0', 'max:50'],
            'source' => ['nullable', 'string', 'max:32'],
            'discount_cents' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:pending,confirmed'],
        ]);

        try {
            $booking = $this->bookings->create(
                $request->user()->hotel,
                $request->user(),
                $validated,
            );
        } catch (ValidationException|BookingConflictException $e) {
            throw $e;
        }

        return response()->json(['data' => $booking], 201);
    }

    public function show(Request $request, Booking $booking)
    {
        $this->authorize('bookings.view');

        $booking->load(['guest', 'rooms.room', 'rooms.ratePlan', 'payments.receiver']);

        return response()->json(['data' => $booking]);
    }

    public function update(Request $request, Booking $booking)
    {
        $this->authorize('bookings.manage');

        $validated = $request->validate([
            'check_in' => ['sometimes', 'date'],
            'check_out' => ['sometimes', 'date', 'after:check_in'],
            'rooms' => ['sometimes', 'array'],
            'rooms.*' => ['integer', 'exists:rooms,id'],
            'adults' => ['sometimes', 'integer', 'min:1'],
            'children' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $booking = $this->bookings->modify($booking, $request->user(), $validated);

        return response()->json(['data' => $booking->load(['guest', 'rooms.room'])]);
    }

    public function checkIn(Request $request, Booking $booking)
    {
        $this->authorize('bookings.manage');

        $booking = $this->bookings->checkIn($booking, $request->user());

        return response()->json(['data' => $booking->load(['guest', 'rooms.room'])]);
    }

    public function checkOut(Request $request, Booking $booking)
    {
        $this->authorize('bookings.manage');

        $booking = $this->bookings->checkOut($booking, $request->user());

        return response()->json(['data' => $booking->load(['guest', 'rooms.room'])]);
    }

    public function cancel(Request $request, Booking $booking)
    {
        $this->authorize('bookings.manage');

        $validated = $request->validate([
            'reason' => ['sometimes', 'string', 'max:255'],
        ]);

        $booking = $this->bookings->cancel($booking, $request->user(), $validated['reason'] ?? null);

        return response()->json(['data' => $booking->load(['guest', 'rooms.room'])]);
    }

    public function accept(Request $request, Booking $booking)
    {
        $this->authorize('bookings.manage');

        $booking = $this->bookings->confirm($booking, $request->user());

        return response()->json(['data' => $booking->load(['guest', 'rooms.room'])]);
    }

    public function noShow(Request $request, Booking $booking)
    {
        $this->authorize('bookings.manage');

        $booking = $this->bookings->cancel($booking, $request->user(), 'Guest did not arrive.', true);

        return response()->json(['data' => $booking->load(['guest', 'rooms.room'])]);
    }

    public function destroy(Request $request, Booking $booking)
    {
        $this->authorize('bookings.manage');

        // Hard-delete only if it was never confirmed into house operations.
        if (in_array($booking->status, ['pending'], true)) {
            $booking->rooms()->delete();
            $booking->delete();

            return response()->json(['message' => 'Booking deleted.']);
        }

        return response()->json([
            'message' => 'Only pending bookings can be deleted; cancel it instead.',
            'error' => 'BOOKING_IN_USE',
        ], 422);
    }
}