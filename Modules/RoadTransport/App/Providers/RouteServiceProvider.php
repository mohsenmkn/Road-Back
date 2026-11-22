<?php

namespace Modules\RoadTransport\App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    protected string $moduleNamespace = 'Modules\\RoadTransport\\App\\Http\\Controllers';

    public function map(): void
    {
        Route::prefix('api')->middleware('api')->namespace($this->moduleNamespace)
            ->group(module_path('RoadTransport', '/routes/api.php'));
    }
}
