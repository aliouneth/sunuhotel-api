<?php

namespace App\Providers;

use App\Services\HotelImport\HotelExcelReader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The reader takes its row budget from config, so it cannot be resolved
        // by the container's constructor injection alone.
        $this->app->singleton(HotelExcelReader::class, fn (): HotelExcelReader => new HotelExcelReader(
            (int) config('hotel_import.max_rows', 20000),
            (int) config('hotel_import.preview_rows', 8),
        ));
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
