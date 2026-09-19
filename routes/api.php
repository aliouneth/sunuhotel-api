<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AmenityController;
use App\Http\Controllers\Api\V1\BookingAvailabilityController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\ExpenseTypeController;
use App\Http\Controllers\Api\V1\GuestAuthController;
use App\Http\Controllers\Api\V1\GuestController;
use App\Http\Controllers\Api\V1\HotelController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PlatformAdminController;
use App\Http\Controllers\Api\V1\PublicHotelController;
use App\Http\Controllers\Api\V1\RatePlanController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\RoomTypeController;
use App\Http\Controllers\Api\V1\TeamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — Sunuhotel
|--------------------------------------------------------------------------
|
| Every authenticated route sits behind `auth:sanctum` + `tenant`. The
| `tenant` middleware pins HotelContext for the request; the HotelScope global
| bound in the BelongsToHotel trait then guarantees each model query is
| restricted to the caller's hotel before it ever reaches SQL.
|
*/

Route::prefix('v1')->group(function () {

    /* ----------------------------- Public ----------------------------- */

    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

    Route::get('/invitations/{token}', [InvitationController::class, 'show']);
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept']);

    Route::get('/hotels/search', [PublicHotelController::class, 'search']);
    Route::get('/hotels/cities', [PublicHotelController::class, 'cities']);
    Route::get('/hotels/{hotel}/public', [PublicHotelController::class, 'show'])->where('hotel', '[a-z0-9-]+');
    Route::get('/hotels/{hotel}/public/availability', [PublicHotelController::class, 'availability'])->where('hotel', '[a-z0-9-]+');
    Route::post('/hotels/{hotel}/public/bookings', [PublicHotelController::class, 'storeBooking'])->where('hotel', '[a-z0-9-]+');
    Route::post('/hotels/{hotel}/public/reviews', [PublicHotelController::class, 'storeReview'])->where('hotel', '[a-z0-9-]+');

    // Public corporate contact info for the registration ("under review") screen.
    Route::get('/platform/support', [PlatformAdminController::class, 'support']);

    /* ---------------------- Guest self-service ------------------------- */
    // Guests log in with email + password to view their own reservations.
    // These live OUTSIDE the `tenant` middleware because a guest record may be
    // found across multiple hotels and `TokenAbilities` scopes staff tokens.
    Route::post('/guests/login', [GuestAuthController::class, 'login']);
    Route::get('/guests/me', [GuestAuthController::class, 'me'])->middleware('auth:sanctum');
    Route::get('/guests/me/bookings', [GuestAuthController::class, 'myBookings'])->middleware('auth:sanctum');

    /* ------------------------ Platform administration ------------------- */

    Route::middleware(['auth:sanctum', 'platform.admin'])->prefix('platform')->group(function () {
        Route::get('/summary', [PlatformAdminController::class, 'summary']);
        Route::get('/hotels', [PlatformAdminController::class, 'hotels']);
        Route::get('/hotels/{hotel}', [PlatformAdminController::class, 'show']);
        Route::put('/hotels/{hotel}', [PlatformAdminController::class, 'update']);
        Route::get('/hotels/{hotel}/rooms', [PlatformAdminController::class, 'rooms']);
        Route::post('/hotels/{hotel}/rooms', [PlatformAdminController::class, 'storeRoom']);
        Route::put('/hotels/{hotel}/rooms/{room}', [PlatformAdminController::class, 'updateRoom']);
        Route::delete('/hotels/{hotel}/rooms/{room}', [PlatformAdminController::class, 'destroyRoom']);
        Route::get('/hotels/{hotel}/room-types', [PlatformAdminController::class, 'roomTypes']);
        Route::post('/hotels/{hotel}/room-types', [PlatformAdminController::class, 'storeRoomType']);
        Route::post('/hotels/{hotel}/room-types/import', [PlatformAdminController::class, 'importRoomTypes']);
        Route::put('/hotels/{hotel}/room-types/{roomType}', [PlatformAdminController::class, 'updateRoomType']);
        Route::delete('/hotels/{hotel}/room-types/{roomType}', [PlatformAdminController::class, 'destroyRoomType']);
        Route::get('/hotels/{hotel}/reviews', [PlatformAdminController::class, 'reviews']);
        Route::post('/hotels/{hotel}/reviews/{review}/moderate', [PlatformAdminController::class, 'moderateReview']);
        Route::get('/hotels/{hotel}/users', [PlatformAdminController::class, 'users']);
        Route::post('/hotels/{hotel}/users', [PlatformAdminController::class, 'storeUser']);
        Route::put('/hotels/{hotel}/users/{user}', [PlatformAdminController::class, 'updateUser']);
        Route::post('/hotels/{hotel}/approve', [PlatformAdminController::class, 'approve']);
        Route::post('/hotels/{hotel}/reject', [PlatformAdminController::class, 'reject']);
        Route::post('/hotels/{hotel}/suspend', [PlatformAdminController::class, 'suspend']);
        Route::get('/settings', [PlatformAdminController::class, 'settings']);
        Route::put('/settings', [PlatformAdminController::class, 'updateSettings']);
    });

    /* --------------------------- Authenticated ------------------------ */

    // Works for both tenant members AND platform admins, so no `tenant` gate.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        // Tenant settings.
        Route::get('/hotel', [HotelController::class, 'show'])->middleware('permission:hotels.view');
        Route::put('/hotel', [HotelController::class, 'update'])->middleware('permission:hotels.update');

        // Team management.
        Route::get('/team', [TeamController::class, 'index']);
        Route::post('/team', [TeamController::class, 'store']);
        Route::post('/team/invitations', [TeamController::class, 'invite']);
        Route::put('/team/{user}', [TeamController::class, 'updateRole']);
        Route::delete('/team/{user}', [TeamController::class, 'destroy']);

        // Rooms & rates.
        Route::apiResource('room-types', RoomTypeController::class)->except(['edit', 'create']);
        Route::apiResource('amenities', AmenityController::class)->except(['edit', 'create', 'show']);
        Route::apiResource('rooms', RoomController::class)->except(['edit', 'create']);
        Route::patch('rooms/{room}/status', [RoomController::class, 'updateStatus']);
        Route::get('rate-plans/{ratePlan}/quote', [RatePlanController::class, 'quote']);
        Route::apiResource('rate-plans', RatePlanController::class)->except(['edit', 'create']);

        // Guests.
        Route::get('guests/lookup', [GuestController::class, 'lookup']);
        Route::get('guests/{guest}/history', [GuestController::class, 'history']);
        Route::apiResource('guests', GuestController::class)->except(['edit', 'create']);

        // Bookings.
        Route::get('bookings/availability', [BookingAvailabilityController::class, 'check']);
        Route::get('bookings/availability/calendar', [BookingAvailabilityController::class, 'calendar']);
        Route::post('bookings/{booking}/check-in', [BookingController::class, 'checkIn']);
        Route::post('bookings/{booking}/check-out', [BookingController::class, 'checkOut']);
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel']);
        Route::post('bookings/{booking}/accept', [BookingController::class, 'accept']);
        Route::post('bookings/{booking}/no-show', [BookingController::class, 'noShow']);
        Route::apiResource('bookings', BookingController::class)->except(['edit', 'create']);

        // Payments (nested under booking).
        Route::get('bookings/{booking}/payments', [PaymentController::class, 'index']);
        Route::post('bookings/{booking}/payments', [PaymentController::class, 'store']);
        Route::post('payments/{payment}/refund', [PaymentController::class, 'refund']);

        // Staff & payroll.
        Route::post('employees/{employee}/pay', [EmployeeController::class, 'pay']);
        Route::apiResource('employees', EmployeeController::class)->except(['edit', 'create']);

        // Expenses.
        Route::get('expenses/summary', [ExpenseController::class, 'summary']);
        Route::apiResource('expense-types', ExpenseTypeController::class)->except(['edit', 'create']);
        Route::apiResource('expenses', ExpenseController::class)->except(['edit', 'create']);

        // Reports & dashboard.
        Route::get('reports/kpis', [ReportController::class, 'kpis']);
        Route::get('reports/occupancy', [ReportController::class, 'occupancy']);
        Route::get('reports/revenue', [ReportController::class, 'revenue']);
        Route::get('reports/export', [ReportController::class, 'export']);

        Route::get('dashboard', [DashboardController::class, 'summary']);
        Route::get('reviews', [ReviewController::class, 'index']);
    });
});

// Health endpoint override: keep the framework default at /up.
Route::get('/up', fn () => response()->json(['status' => 'ok']));