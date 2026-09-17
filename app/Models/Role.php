<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Hotel-scoped role backed by the Spatie teams feature.
 *
 * Semantics used by Sunuhotel:
 *   - Role DEFINITIONS are global (hotel_id = NULL): "manager" has the same
 *     power in every hotel. The permission matrix is fixed per role name.
 *   - Role ASSIGNMENTS (model_has_roles) always carry the hotel_id, so a user
 *     is only "manager" inside their own tenant — strict isolation.
 *
 * @property int|null $hotel_id
 */
class Role extends SpatieRole
{
public const LABELS = [
        'owner' => 'Owner',
        'manager' => 'Manager',
        'front-desk' => 'Front Desk',
        'accountant' => 'Accountant',
    ];

    public function hotel()
    {
        return $this->belongsTo(Hotel::class, config('permission.column_names.team_foreign_key'));
    }

    /**
* Idempotent: materialises the global permission set and the four role
     * definitions with their fixed permission matrices. Safe to call in
     * requests (cheap firstOrCreate) and in the database seeder.
     */
    public static function seedPolicies(): void
    {
        $permissionsByRole = [
            'owner' => Permission::ALL,
'manager' => [
                'hotels.update', 'hotels.view',
                'users.manage',
                'rooms.manage', 'rooms.view',
                'rate-plans.manage',
                'bookings.manage', 'bookings.view',
                'guests.manage', 'guests.view',
                'payments.manage', 'payments.view',
                'employees.manage', 'employees.view',
                'expenses.manage', 'expenses.view',
                'reports.view',
            ],
            'front-desk' => [
                'rooms.view',
                'bookings.manage', 'bookings.view',
                'guests.manage', 'guests.view',
                'payments.create', 'payments.view',
                'employees.view',
            ],
            'accountant' => [
                'hotels.view', 'bookings.view',
                'payments.manage', 'payments.view', 'reports.view',
                'employees.view',
                'expenses.view', 'expenses.manage',
            ],
        ];

        // 1. Materialise permissions (unique by name + guard).
        foreach ($permissionsByRole as $permissions) {
            foreach ($permissions as $permission) {
                Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            }
        }

        // 2. Materialise role definitions (team NULL) with their permission sets.
        foreach ($permissionsByRole as $roleName => $permissions) {
            $role = static::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $named = Permission::query()->whereIn('name', $permissions)->get();

            if ($roleName === 'owner') {
                $role->syncPermissions(Permission::query()->get());
            } else {
                $role->syncPermissions($named);
            }
        }
    }
}
