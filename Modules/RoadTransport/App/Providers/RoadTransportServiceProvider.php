<?php

namespace Modules\RoadTransport\App\Providers;

use Illuminate\Support\ServiceProvider;

class RoadTransportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path('RoadTransport', 'Database/migrations'));
    }

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }
}
