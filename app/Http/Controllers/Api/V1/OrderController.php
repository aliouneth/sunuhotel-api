<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;

/**
 * On-site orders (restaurant / spa / leisure) attached to a booking. Orders
 * are itemised, untaxed and roll up into the booking total.
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request, Booking $booking)
    {
        $this->authorize('orders.view');

        return response()->json([
            'data' => $booking->orders()->with('items')->latest()->get(),
        ]);
    }

    public function store(Request $request, Booking $booking)
    {
        $this->authorize('orders.manage');

        $validated = $request->validate([
            'category' => ['required', 'in:restaurant,spa,leisure'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:191'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'items.*.unit_price_cents' => ['required', 'integer', 'min:0'],
        ]);

        $order = $this->orders->create($booking, $request->user(), $validated);

        return response()->json(['data' => $order], 201);
    }

    public function update(Request $request, Order $order)
    {
        $this->authorize('orders.manage');

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:191'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'items.*.unit_price_cents' => ['required', 'integer', 'min:0'],
        ]);

        $order = $this->orders->update($order, $request->user(), $validated);

        return response()->json(['data' => $order]);
    }

    public function void(Request $request, Order $order)
    {
        $this->authorize('orders.manage');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $order = $this->orders->void($order, $request->user(), $validated['reason'] ?? null);

        return response()->json(['data' => $order]);
    }
}
