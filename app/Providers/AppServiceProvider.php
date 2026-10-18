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
        // Counted per IP before the signature is checked, so junk cannot be used to hammer the app.
        RateLimiter::for('paystack-webhook', fn (Request $request) => Limit::perMinute(max(1, (int) config('paystack.webhook_attempts_per_minute', 120)))->by($request->ip()));
    }
}
