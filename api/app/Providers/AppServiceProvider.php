<?php

namespace App\Providers;

use App\Services\Routing\LocalPedestrianGraphRouter;
use App\Services\Routing\PedestrianRouter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(PedestrianRouter::class, LocalPedestrianGraphRouter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
    }
}
