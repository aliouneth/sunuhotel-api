<?php

use App\Http\Controllers\Api\V1\AmenityController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingAvailabilityController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\CountryTaxRateController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\ExpenseTypeController;
use App\Http\Controllers\Api\V1\GuestAuthController;
use App\Http\Controllers\Api\V1\GuestController;
use App\Http\Controllers\Api\V1\HotelBillingController;
use App\Http\Controllers\Api\V1\HotelController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PayDunyaBookingController;
use App\Http\Controllers\Api\V1\PayDunyaController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PlatformAdminController;
use App\Http\Controllers\Api\V1\PlatformGuestController;
use App\Http\Controllers\Api\V1\PlatformHotelImportController;
use App\Http\Controllers\Api\V1\PlatformUserController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\PublicHotelController;
use App\Http\Controllers\Api\V1\RatePlanController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\RoomTypeController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SubscriptionPlanController;
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

    // Anti-spam / brute-force: strict IP-based limits on unauthenticated writes.
    Route::middleware('throttle:api.write')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    });

    Route::get('/invitations/{token}', [InvitationController::class, 'show'])
        ->middleware('throttle:api.read.standard');
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])
        ->middleware('throttle:api.write');

    // Public hotel browsing (read-heavy, generous limits)
    Route::get('/hotels/search', [PublicHotelController::class, 'search'])
        ->middleware('throttle:api.read');
    Route::get('/hotels/cities', [PublicHotelController::class, 'cities'])
        ->middleware('throttle:api.read');
    Route::get('/hotels/promotions', [PublicHotelController::class, 'promotions'])
        ->middleware('throttle:api.read');
    Route::get('/hotels/{hotel}/public', [PublicHotelController::class, 'show'])
        ->where('hotel', '[a-z0-9-]+')
        ->middleware('throttle:api.read');
    Route::get('/hotels/{hotel}/public/availability', [PublicHotelController::class, 'availability'])
        ->where('hotel', '[a-z0-9-]+')
        ->middleware('throttle:api.read.standard');

    // Guest-generated content is a spam target: stricter limits.
    Route::post('/hotels/{hotel}/public/bookings', [PublicHotelController::class, 'storeBooking'])
        ->where('hotel', '[a-z0-9-]+')
        ->middleware('throttle:api.heavy'); // 10/min - booking creation
    Route::post('/hotels/{hotel}/public/reviews', [PublicHotelController::class, 'storeReview'])
        ->where('hotel', '[a-z0-9-]+')
        ->middleware('throttle:api.heavy'); // 10/min - review creation

    // Public corporate contact info for the registration ("under review") screen.
    Route::get('/platform/support', [PlatformAdminController::class, 'support'])
        ->middleware('throttle:api.read.standard');

    // First-party audience measurement beacon (public pages, consent-gated).
    Route::post('/analytics/collect', [AnalyticsController::class, 'collect'])
        ->middleware('throttle:api.read'); // 120/min - analytics beacon

    // PayDunya IPN (Instant Payment Notification). Must stay public: PayDunya
    // posts the transaction result here server-to-server, authenticated by a
    // SHA-512 hash of the master key plus a confirm re-check, not by a token.
    Route::post('/paydunya/ipn', [PayDunyaController::class, 'ipn'])
        ->middleware('throttle:api.read.standard');

    // PayDunya IPN for a guest reservation + the public status lookup the
    // browser uses on the return_url screen. Same untrusted-callback rules.
    Route::post('/paydunya/booking-ipn', [PayDunyaBookingController::class, 'ipn'])
        ->middleware('throttle:api.read.standard');
    Route::get('/paydunya/booking-status/{token}', [PayDunyaBookingController::class, 'status'])
        ->middleware('throttle:api.read.standard');

    // Guest self-service auth (outside tenant scope) — stricter limits, IP-based.
    Route::middleware('throttle:api.write')->group(function () {
        Route::post('/guest/login', [GuestAuthController::class, 'login']);
        Route::post('/guest/forgot-password', [GuestAuthController::class, 'forgotPassword']);
        Route::post('/guest/reset-password', [GuestAuthController::class, 'resetPassword']);
    });

    Route::middleware(['auth:sanctum', 'throttle:api.read.standard'])->group(function () {
        Route::get('/guest/me', [GuestAuthController::class, 'me']);
        Route::get('/guest/me/bookings', [GuestAuthController::class, 'myBookings']);
        Route::post('/guest/me/reviews', [GuestAuthController::class, 'storeReview'])
            ->middleware('throttle:api.write'); // Writing a review
    });

    /* ------------------------ Platform administration ------------------- */

    Route::middleware(['auth:sanctum', 'platform.admin'])->prefix('platform')->group(function () {
        // Read-heavy platform endpoints
        Route::get('/summary', [PlatformAdminController::class, 'summary'])
            ->middleware('throttle:api.read');
        Route::get('/analytics', [AnalyticsController::class, 'summary'])
            ->middleware('throttle:api.read');
        Route::get('/hotels', [PlatformAdminController::class, 'hotels'])
            ->middleware('throttle:api.read.standard');
        Route::get('/hotels/{hotel}', [PlatformAdminController::class, 'show'])
            ->middleware('throttle:api.read.standard');
        Route::get('/hotels/{hotel}/rooms', [PlatformAdminController::class, 'rooms'])
            ->middleware('throttle:api.read.standard');
        Route::get('/hotels/{hotel}/room-types', [PlatformAdminController::class, 'roomTypes'])
            ->middleware('throttle:api.read.standard');
        Route::get('/hotels/{hotel}/reviews', [PlatformAdminController::class, 'reviews'])
            ->middleware('throttle:api.read.standard');
        Route::get('/hotels/{hotel}/users', [PlatformAdminController::class, 'users'])
            ->middleware('throttle:api.read.standard');
        Route::get('/hotels/{hotel}/guests', [PlatformGuestController::class, 'index'])
            ->middleware('throttle:api.read.standard');
        Route::get('/hotels/{hotel}/guests/{guest}', [PlatformGuestController::class, 'show'])
            ->middleware('throttle:api.read.standard');
        Route::get('/guests', [PlatformGuestController::class, 'all'])
            ->middleware('throttle:api.read.standard');
        Route::get('/guests/{guest}', [PlatformGuestController::class, 'show'])
            ->middleware('throttle:api.read.standard');
        // Platform-wide USER (staff) directory across every hotel.
        Route::get('/users', [PlatformUserController::class, 'index'])
            ->middleware('throttle:api.read.standard');

        // Write operations (moderate)
        Route::post('/hotels', [PlatformAdminController::class, 'storeHotel'])
            ->middleware('throttle:api.write');
        Route::put('/hotels/{hotel}', [PlatformAdminController::class, 'update'])
            ->middleware('throttle:api.write');
        Route::post('/hotels/{hotel}/rooms', [PlatformAdminController::class, 'storeRoom'])
            ->middleware('throttle:api.write');
        Route::put('/hotels/{hotel}/rooms/{room}', [PlatformAdminController::class, 'updateRoom'])
            ->middleware('throttle:api.write');
        Route::delete('/hotels/{hotel}/rooms/{room}', [PlatformAdminController::class, 'destroyRoom'])
            ->middleware('throttle:api.write');
        Route::post('/hotels/{hotel}/room-types', [PlatformAdminController::class, 'storeRoomType'])
            ->middleware('throttle:api.write');
        Route::put('/hotels/{hotel}/room-types/{roomType}', [PlatformAdminController::class, 'updateRoomType'])
            ->middleware('throttle:api.write');
        Route::delete('/hotels/{hotel}/room-types/{roomType}', [PlatformAdminController::class, 'destroyRoomType'])
            ->middleware('throttle:api.write');
        Route::post('/hotels/{hotel}/reviews/{review}/moderate', [PlatformAdminController::class, 'moderateReview'])
            ->middleware('throttle:api.write');
        Route::post('/hotels/{hotel}/users', [PlatformAdminController::class, 'storeUser'])
            ->middleware('throttle:api.write');
        Route::put('/hotels/{hotel}/users/{user}', [PlatformAdminController::class, 'updateUser'])
            ->middleware('throttle:api.write');
        Route::put('/guests/{guest}', [PlatformGuestController::class, 'update'])
            ->middleware('throttle:api.write');
        Route::post('/users', [PlatformUserController::class, 'store'])
            ->middleware('throttle:api.write');
        Route::put('/users/{user}', [PlatformUserController::class, 'update'])
            ->middleware('throttle:api.write');

        // Heavy operations (imports, bulk actions)
        Route::post('/hotels/{hotel}/room-types/import', [PlatformAdminController::class, 'importRoomTypes'])
            ->middleware('throttle:api.heavy');
        Route::get('/hotel-imports', [PlatformHotelImportController::class, 'index'])
            ->middleware('throttle:api.read.standard');
        Route::post('/hotel-imports/upload', [PlatformHotelImportController::class, 'upload'])
            ->middleware('throttle:api.heavy');
        Route::post('/hotel-imports/run', [PlatformHotelImportController::class, 'run'])
            ->middleware('throttle:api.heavy');
        Route::get('/hotel-imports/template', [PlatformHotelImportController::class, 'template'])
            ->middleware('throttle:api.read.standard');
        Route::get('/hotel-imports/{hotelImport}', [PlatformHotelImportController::class, 'show'])
            ->middleware('throttle:api.read.standard');

        // Hotel status changes
        Route::post('/hotels/{hotel}/approve', [PlatformAdminController::class, 'approve'])
            ->middleware('throttle:api.write');
        Route::post('/hotels/{hotel}/reject', [PlatformAdminController::class, 'reject'])
            ->middleware('throttle:api.write');
        Route::post('/hotels/{hotel}/suspend', [PlatformAdminController::class, 'suspend'])
            ->middleware('throttle:api.write');

        // Settings
        Route::get('/settings', [PlatformAdminController::class, 'settings'])
            ->middleware('throttle:api.read.standard');
        Route::put('/settings', [PlatformAdminController::class, 'updateSettings'])
            ->middleware('throttle:api.write');

        // Subscription plans & hotel assignment.
        Route::apiResource('plans', SubscriptionPlanController::class)->except(['edit', 'create'])
            ->middleware('throttle:api.read.standard');

        // Hotel promotions (platform-managed, billed to the hotel).
        Route::apiResource('promotions', PromotionController::class)->except(['edit', 'create'])
            ->middleware('throttle:api.read.standard');

        // Platform-managed default tax (VAT) per country — PURE fallback used
        // only when a hotel leaves its own tax_rate at 0.
        Route::apiResource('country-tax-rates', CountryTaxRateController::class)->except(['edit', 'create'])
            ->middleware('throttle:api.read.standard');
        Route::get('subscriptions', [SubscriptionController::class, 'index'])
            ->middleware('throttle:api.read.standard');
        Route::get('subscriptions/invoices', [SubscriptionController::class, 'invoicesIndex'])
            ->middleware('throttle:api.read.standard');
        Route::get('subscriptions/{hotel}', [SubscriptionController::class, 'show'])
            ->middleware('throttle:api.read.standard');
        Route::post('subscriptions/{hotel}/assign', [SubscriptionController::class, 'assign'])
            ->middleware('throttle:api.write');
        Route::put('subscriptions/{hotel}', [SubscriptionController::class, 'update'])
            ->middleware('throttle:api.write');
        Route::post('subscriptions/{hotel}/deactivate', [SubscriptionController::class, 'deactivate'])
            ->middleware('throttle:api.write');
        Route::post('subscriptions/{hotel}/reactivate', [SubscriptionController::class, 'reactivate'])
            ->middleware('throttle:api.write');
        Route::get('subscriptions/{hotel}/invoices', [SubscriptionController::class, 'invoices'])
            ->middleware('throttle:api.read.standard');
        Route::post('subscriptions/{hotel}/invoices/{invoice}/mark-paid', [SubscriptionController::class, 'markPaid'])
            ->middleware('throttle:api.payment');
        Route::post('subscriptions/{hotel}/wallet-credit', [SubscriptionController::class, 'creditWallet'])
            ->middleware('throttle:api.payment');
    });

    /* --------------------------- Authenticated ------------------------ */

    // Works for both tenant members AND platform admins, so no `tenant` gate.
    Route::middleware(['auth:sanctum', 'throttle:api.read.standard'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        // Tenant settings (read/write)
        Route::get('/hotel', [HotelController::class, 'show'])
            ->middleware(['permission:hotels.view', 'throttle:api.read.standard']);
        Route::put('/hotel', [HotelController::class, 'update'])
            ->middleware(['permission:hotels.update', 'throttle:api.write']);

        // Hotel billing (read + payment initiation)
        Route::get('/billing', [HotelBillingController::class, 'index'])
            ->middleware(['permission:payments.view', 'throttle:api.read.standard']);
        Route::get('/billing/payment-status/{token}', [HotelBillingController::class, 'paymentStatus'])
            ->middleware(['permission:payments.view', 'throttle:api.read.standard']);

        // Pay a monthly invoice online via PayDunya (returns a payment URL).
        Route::post('/billing/invoices/{invoice}/paydunya', [PayDunyaController::class, 'initiate'])
            ->middleware(['permission:payments.manage', 'throttle:api.payment']);

        // Team management.
        Route::get('/team', [TeamController::class, 'index'])
            ->middleware('throttle:api.read.standard');
        Route::post('/team', [TeamController::class, 'store'])
            ->middleware('throttle:api.write');
        Route::post('/team/invitations', [TeamController::class, 'invite'])
            ->middleware('throttle:api.write');
        Route::put('/team/{user}', [TeamController::class, 'updateRole'])
            ->middleware('throttle:api.write');
        Route::delete('/team/{user}', [TeamController::class, 'destroy'])
            ->middleware('throttle:api.write');

        // Rooms & rates (read-heavy resources)
        Route::apiResource('room-types', RoomTypeController::class)->except(['edit', 'create'])
            ->middleware('throttle:api.read.standard');
        Route::apiResource('amenities', AmenityController::class)->except(['edit', 'create', 'show'])
            ->middleware('throttle:api.read.standard');
        Route::apiResource('rooms', RoomController::class)->except(['edit', 'create'])
            ->middleware('throttle:api.read.standard');
        Route::patch('rooms/{room}/status', [RoomController::class, 'updateStatus'])
            ->middleware('throttle:api.write');
        Route::get('rate-plans/{ratePlan}/quote', [RatePlanController::class, 'quote'])
            ->middleware('throttle:api.read.standard');
        Route::apiResource('rate-plans', RatePlanController::class)->except(['edit', 'create'])
            ->middleware('throttle:api.read.standard');

        // Guests.
        Route::get('guests/lookup', [GuestController::class, 'lookup'])
            ->middleware('throttle:api.read.standard');
        Route::get('guests/{guest}/history', [GuestController::class, 'history'])
            ->middleware('throttle:api.read.standard');
        Route::apiResource('guests', GuestController::class)->except(['edit', 'create'])
            ->middleware('throttle:api.read.standard');

        // Bookings.
        Route::get('bookings/availability', [BookingAvailabilityController::class, 'check'])
            ->middleware('throttle:api.read.standard');
        Route::get('bookings/availability/calendar', [BookingAvailabilityController::class, 'calendar'])
            ->middleware('throttle:api.heavy'); // Calendar queries can be expensive
        Route::post('bookings/{booking}/check-in', [BookingController::class, 'checkIn'])
            ->middleware('throttle:api.booking.action');
        Route::post('bookings/{booking}/check-out', [BookingController::class, 'checkOut'])
            ->middleware('throttle:api.booking.action');
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])
            ->middleware('throttle:api.booking.action');
        Route::post('bookings/{booking}/accept', [BookingController::class, 'accept'])
            ->middleware('throttle:api.booking.action');
        Route::post('bookings/{booking}/no-show', [BookingController::class, 'noShow'])
            ->middleware('throttle:api.booking.action');
        Route::apiResource('bookings', BookingController::class)->except(['edit', 'create'])
            ->middleware('throttle:api.read.standard');

        // Payments (nested under booking).
        Route::get('bookings/{booking}/payments', [PaymentController::class, 'index'])
            ->middleware('throttle:api.read.standard');
        Route::post('bookings/{booking}/payments', [PaymentController::class, 'store'])
            ->middleware('throttle:api.payment');
        Route::post('payments/{payment}/refund', [PaymentController::class, 'refund'])
            ->middleware('throttle:api.payment');

        // On-site orders (restaurant / spa / leisure), nested under booking.
        Route::get('bookings/{booking}/orders', [OrderController::class, 'index'])
            ->middleware('throttle:api.read.standard');
        Route::post('bookings/{booking}/orders', [OrderController::class, 'store'])
            ->middleware('throttle:api.write');
        Route::put('orders/{order}', [OrderController::class, 'update'])
            ->middleware('throttle:api.write');
        Route::post('orders/{order}/void', [OrderController::class, 'void'])
            ->middleware('throttle:api.write');

        // Staff & payroll.
        Route::post('employees/{employee}/pay', [EmployeeController::class, 'pay'])
            ->middleware('throttle:api.payment');
        Route::apiResource('employees', EmployeeController::class)->except(['edit', 'create'])
            ->middleware('throttle:api.read.standard');

        // Expenses.
        Route::get('expenses/summary', [ExpenseController::class, 'summary'])
            ->middleware('throttle:api.read.standard');
        Route::apiResource('expense-types', ExpenseTypeController::class)->except(['edit', 'create'])
            ->middleware('throttle:api.read.standard');
        Route::apiResource('expenses', ExpenseController::class)->except(['edit', 'create'])
            ->middleware('throttle:api.read.standard');

        // Reports & dashboard (read-heavy, some expensive).
        Route::get('reports/kpis', [ReportController::class, 'kpis'])
            ->middleware('throttle:api.read');
        Route::get('reports/occupancy', [ReportController::class, 'occupancy'])
            ->middleware('throttle:api.heavy');
        Route::get('reports/revenue', [ReportController::class, 'revenue'])
            ->middleware('throttle:api.heavy');
        Route::get('reports/profit-loss', [ReportController::class, 'profitLoss'])
            ->middleware('throttle:api.heavy');
        Route::get('reports/export', [ReportController::class, 'export'])
            ->middleware('throttle:api.heavy'); // Exports can be large

        Route::get('dashboard', [DashboardController::class, 'summary'])
            ->middleware('throttle:api.read');
        Route::get('reviews', [ReviewController::class, 'index'])
            ->middleware('throttle:api.read.standard');
    });
});

// Health endpoint override: keep the framework default at /up.
Route::get('/up', fn () => response()->json(['status' => 'ok']));
