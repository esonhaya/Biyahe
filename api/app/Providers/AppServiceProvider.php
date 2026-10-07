<?php

namespace App\Providers;

use App\Services\Routing\LocalPedestrianGraphRouter;
use App\Services\Routing\PedestrianRouter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('commuter-reports', function (Request $request): Limit {
            $key = hash('sha256', (string) $request->ip().'|'.(string) $request->userAgent());

            return Limit::perMinute((int) config('biyahe.disruptions.report_rate_limit_per_minute', 5))->by($key);
        });
    }
}
