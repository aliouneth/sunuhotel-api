<?php

use App\Exceptions\BookingConflictException;
use App\Exceptions\ValidationException;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException as LaravelValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'tenant' => EnsureTenantContext::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'can' => \Illuminate\Auth\Middleware\Authorize::class,
            'platform.admin' => EnsurePlatformAdmin::class,
        ]);
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $billing = new \App\Services\BillingService();

        // 1st 09:00 â€” generate this month's invoices, settle from wallet credit.
        $schedule->call(fn () => $billing->ensureCurrentMonth())
            ->name('billing.ensureCurrentMonth')
            ->monthlyOn(1, '09:00')
            ->timezone(config('app.timezone'))
            ->withoutOverlapping();

        // 5th 09:00 â€” mark last month's still-pending invoices overdue.
        $schedule->call(fn () => $billing->markOverdue())
            ->name('billing.markOverdue')
            ->monthlyOn(5, '09:00')
            ->timezone(config('app.timezone'))
            ->withoutOverlapping();

        // 6th 09:00 â€” suspend hotels whose previous-month invoice is overdue.
        $schedule->call(fn () => $billing->suspendOverdue())
            ->name('billing.suspendOverdue')
            ->monthlyOn(6, '09:00')
            ->timezone(config('app.timezone'))
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Laravel validation -> consistent error envelope.
        $exceptions->render(function (LaravelValidationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        // Domain rule violation -> 422.
        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => $e->reason,
                ], 422);
            }
        });

        // Overbooking conflict -> 409.
        $exceptions->render(function (BookingConflictException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => $e->reason,
                ], 409);
            }
        });

        // Unauthorised model lookups should not leak that foreign ids exist.
        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Resource not found.',
                    'error' => 'NOT_FOUND',
                ], 404);
            }
        });
    })->create();