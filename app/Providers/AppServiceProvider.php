<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $maxAttempts = (int) env('LOGIN_RATE_LIMIT', 5);
            $window = max(1, (int) env('LOGIN_RATE_LIMIT_WINDOW', 1));

            return [
                Limit::perMinutes($window, $maxAttempts)->by($request->input('email').$request->ip()),
            ];
        });
    }
}
