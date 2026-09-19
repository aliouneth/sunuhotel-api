<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Support\Tenancy\HotelContext;
use Illuminate\Http\Request;

/**
 * Tenant-facing review widget: average, count and the latest reviews for the
 * current hotel (read-only in the dashboard).
 */
class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $hotelId = HotelContext::id();
        abort_if($hotelId === null, 403, 'No hotel context.');

        $base = Review::query()->where('hotel_id', $hotelId);

        $count = (clone $base)->count();
        $average = $count > 0 ? round((float) (clone $base)->avg('rating'), 1) : null;

        $recent = (clone $base)
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(fn (Review $r) => [
                'id' => $r->id,
                'guest_name' => $r->guest_name,
                'rating' => $r->rating,
                'title' => $r->title,
                'comment' => $r->comment,
                'verified' => $r->verified,
                'created_at' => $r->created_at?->toISOString(),
            ]);

        return response()->json([
            'data' => [
                'average' => $average,
                'count' => $count,
                'recent' => $recent,
            ],
        ]);
    }
}