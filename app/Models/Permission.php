<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Global permission (Spatie teams mode keeps permissions global; their
 * grants are what gets scoped per hotel).
 */
class Permission extends SpatiePermission
{
    /** Every permission known to the platform. */
    public const ALL = [
        'hotels.view',
        'hotels.update',
        'users.manage',
        'rooms.view',
        'rooms.manage',
        'rate-plans.manage',
        'bookings.view',
        'bookings.manage',
        'guests.view',
        'guests.manage',
        'payments.view',
        'payments.create',
        'payments.manage',
        'employees.view',
        'employees.manage',
        'expenses.view',
        'expenses.manage',
        'reports.view',
    ];
}