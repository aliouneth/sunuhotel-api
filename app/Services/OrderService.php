<?php

namespace App\Services;

use App\Exceptions\ValidationException as DomainValidationException;
use App\Models\Booking;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;

/**
 * On-site orders engine.
 *
 * Orders (restaurant / spa / leisure) are itemised and UNTAXED. Their active
 * total is rolled into the booking as `orders_cents`, so the booking total is
 * always `subtotal + tax + orders - discount` and the existing payment and
 * balance flow keeps working unchanged.
 */
final class OrderService
{
    /**
     * @param array{
     *     category: string,
     *     notes?: string|null,
     *     items: array<int, array{description: string, quantity?: int, unit_price_cents: int}>,
     * } $payload
     */
    public function create(Booking $booking, User $actor, array $payload): Order
    {
        $this->assertCategoryEnabled($booking, $payload['category']);
        $this->assertBookingEditable($booking);
        $this->assertItemsPresent($payload['items'] ?? []);

        return \DB::transaction(function () use ($booking, $actor, $payload) {
            $order = Order::create([
                'hotel_id' => $booking->hotel_id,
                'booking_id' => $booking->id,
                'category' => $payload['category'],
                'status' => 'open',
                'notes' => $payload['notes'] ?? null,
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ]);

            $this->writeItems($order, $payload['items']);
            $this->recalculateOrderTotal($order);
            $this->syncBooking($booking);

            AuditLogger::critical($order, 'order.created', [
                'category' => $order->category,
                'total_cents' => $order->total_cents,
            ]);

            return $order->fresh(['items', 'booking']);
        });
    }

    /**
     * Replace the item list of an open order. The order total and the booking
     * rollup are recomputed.
     *
     * @param  array{notes?: string|null, items: array<int, array{description: string, quantity?: int, unit_price_cents: int}>}  $payload
     */
    public function update(Order $order, User $actor, array $payload): Order
    {
        $this->assertOrderOpen($order);
        $this->assertBookingEditable($order->booking);
        $this->assertItemsPresent($payload['items'] ?? []);

        return \DB::transaction(function () use ($order, $actor, $payload) {
            $order->update(['notes' => $payload['notes'] ?? $order->notes]);

            OrderItem::query()->where('order_id', $order->id)->delete();
            $this->writeItems($order, $payload['items']);
            $this->recalculateOrderTotal($order);
            $this->syncBooking($order->booking);

            AuditLogger::critical($order, 'order.updated', [
                'by' => $actor->name,
                'total_cents' => $order->total_cents,
            ]);

            return $order->fresh(['items', 'booking']);
        });
    }

    /**
     * Void an order: kept for the audit trail but excluded from the booking
     * total.
     */
    public function void(Order $order, User $actor, ?string $reason = null): Order
    {
        $this->assertOrderOpen($order);

        return \DB::transaction(function () use ($order, $actor, $reason) {
            $order->update([
                'status' => 'void',
                'notes' => $reason !== null ? trim(($order->notes ? $order->notes."\n" : '').$reason) : $order->notes,
            ]);

            $this->syncBooking($order->booking);

            AuditLogger::critical($order, 'order.voided', [
                'by' => $actor->name,
                'reason' => $reason,
            ]);

            return $order->fresh(['items', 'booking']);
        });
    }

    /**
     * Recompute the booking's orders subtotal from its open orders and refresh
     * the booking total accordingly.
     */
    public function syncBooking(Booking $booking): void
    {
        $orders = (int) $booking->orders()->where('status', 'open')->sum('total_cents');

        $booking->update([
            'orders_cents' => $orders,
            'total_cents' => max(0, $booking->subtotal_cents + $booking->tax_cents + $orders - $booking->discount_cents),
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<int, array{description: string, quantity?: int, unit_price_cents: int}>  $items
     */
    private function writeItems(Order $order, array $items): void
    {
        foreach ($items as $item) {
            $quantity = (int) ($item['quantity'] ?? 1);
            $unit = (int) $item['unit_price_cents'];

            $order->items()->create([
                'hotel_id' => $order->hotel_id,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price_cents' => $unit,
                'line_total_cents' => $quantity * $unit,
            ]);
        }
    }

    private function recalculateOrderTotal(Order $order): void
    {
        $total = (int) OrderItem::query()->where('order_id', $order->id)->sum('line_total_cents');
        $order->update(['total_cents' => $total]);
    }

    private function assertItemsPresent(array $items): void
    {
        if (count($items) === 0) {
            throw new DomainValidationException('An order must have at least one item.', 'NO_ORDER_ITEMS');
        }
    }

    private function assertCategoryEnabled(Booking $booking, string $category): void
    {
        if (! in_array($category, Order::CATEGORIES, true)) {
            throw new DomainValidationException('Unknown order category.', 'INVALID_ORDER_CATEGORY');
        }

        $hotel = $booking->hotel;
        $enabled = match ($category) {
            'restaurant' => (bool) $hotel->has_restaurant,
            'spa' => (bool) $hotel->has_spa,
            'leisure' => (bool) $hotel->has_leisure,
        };

        if (! $enabled) {
            throw new DomainValidationException("This hotel does not offer {$category} service.", 'FEATURE_DISABLED');
        }
    }

    private function assertBookingEditable(Booking $booking): void
    {
        if (! in_array($booking->status, Order::EDITABLE_BOOKING_STATUSES, true)) {
            throw new DomainValidationException('Orders can only be changed while the booking is active.', 'BOOKING_NOT_EDITABLE');
        }
    }

    private function assertOrderOpen(Order $order): void
    {
        if (! $order->isOpen()) {
            throw new DomainValidationException('This order has been voided.', 'ORDER_VOID');
        }
    }
}
