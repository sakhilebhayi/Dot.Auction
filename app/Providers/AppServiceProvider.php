<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        // The read-only list of settlement payments. There is no operator flag on users yet, so
        // operators are the verified accounts whose email is listed in AUCTION_OPERATOR_EMAILS
        // (exact match, case-blind).
        Gate::define('view-settlement-payments', function (User $user): bool {
            if ($user->email_verified_at === null) {
                return false;
            }

            return in_array(strtolower(trim((string) $user->email)), array_map('strtolower', (array) config('auction.operator_emails', [])), true);
        });

        // Counted per IP before the signature is checked, so junk cannot be used to hammer the app.
        RateLimiter::for('paystack-webhook', fn (Request $request) => Limit::perMinute(max(1, (int) config('paystack.webhook_attempts_per_minute', 120)))->by($request->ip()));
    }
}
