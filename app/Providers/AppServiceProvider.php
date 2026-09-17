<?php

namespace App\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // MySQL 8.4 (WAMP) indexes utf8mb4 keys with a 1000-byte ceiling.
        // 191 chars * 4 bytes = 764 bytes, safely under the limit for unique indexes.
        Schema::defaultStringLength(191);

        // Apply the tenant hotel_id FK convention on every tenant-owned table
        // created through the schema builder from this point forward.
        Blueprint::macro('hotelForeignKey', function () {
            /* @var Blueprint $this */
            $this->unsignedBigInteger('hotel_id');
            $this->index('hotel_id');
        });
    }
}