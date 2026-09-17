<?php

namespace Tests;

use App\Support\Tenancy\HotelContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the tenant context + spatie team pinning between tests.
        HotelContext::clear();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('permissions')) {
            \App\Models\Role::seedPolicies();
        }
    }
}