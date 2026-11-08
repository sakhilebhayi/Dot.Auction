<?php

namespace App\Providers;

use App\Services\DisplayTime;
use App\Services\InfoDot\E2eFakeIdentityClient;
use App\Services\InfoDot\IdentityClient;
use App\Services\Paystack\E2eFakePaystackClient;
use App\Services\Paystack\PaystackClient;
use Carbon\Carbon as BaseCarbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // TESTING ONLY: the real-browser suite (tests/e2e/auction) cannot use Http::fake(), so it asks for a fake Paystack client.
        // Never bound in production: the environment must be local or testing AND the flag must be on.
        if ($this->app->environment('local', 'testing') && config('paystack.e2e_fake') === true) {
            $this->app->singleton(PaystackClient::class, E2eFakePaystackClient::class);
        }

        // Browser end-to-end suite only (tests/e2e/auction-b): a stand-in for InfoDot's redeem endpoint. Never bound in production.
        if ($this->app->environment('local', 'testing') && env('AUCTION_E2E_FAKE_INFODOT')) {
            $this->app->bind(IdentityClient::class, E2eFakeIdentityClient::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // /up answered 200 whenever PHP booted, even with the database down. The framework dispatches DiagnosingHealth
        // before it renders the page: a failing query turns the answer into a 500 (the exception text is never shown).
        // Display-zone helpers (R-42 F-11): `$at->local()` is a copy in the display zone, `$at->localFormat('H:i', true)`
        // prints it ("18:00 SAST"), and `@localtime($at, 'H:i', true)` does the same in a view. Storage stays UTC.
        BaseCarbon::macro('local', function () {
            return DisplayTime::toLocal($this);
        });
        BaseCarbon::macro('localFormat', function (?string $format = null, bool $withZone = false) {
            return DisplayTime::format($this, $format, $withZone);
        });
        Blade::directive('localtime', fn (string $expression) => "<?php echo e(\\App\\Services\\DisplayTime::format({$expression})); ?>");

        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        // Per-person limits (the signed-in user, else the IP address). Generous for a human, small for a script.
        $person = fn (Request $request): string => $request->user()?->getAuthIdentifier() ? 'u'.$request->user()->getAuthIdentifier() : 'ip'.$request->ip();
        // Only a text search is the expensive read (a scan on the title); plain browsing and the filter tabs are not counted.
        RateLimiter::for('browse', fn (Request $request) => $request->filled('q') ? Limit::perMinute(60)->by($person($request)) : Limit::none());
        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(60)->by($person($request)));       // listing edits, watch, notification actions
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by($person($request)));      // photo uploads (CPU and memory)
        // Every Livewire call: the bid panel's poll (every 10 s), the bell (every 30 s), clicks. 300 a minute is five a second.
        RateLimiter::for('livewire', fn (Request $request) => Limit::perMinute(300)->by($person($request)));
        // Fortify's own forms: the password-reset mail is the one an attacker can aim at somebody else's inbox.
        RateLimiter::for('fortify-forms', fn (Request $request) => $request->routeIs('password.email')
            ? Limit::perMinute(5)->by('reset|'.$request->ip().'|'.Str::lower((string) $request->input('email')))
            : Limit::perMinute(60)->by($person($request)));

        // Counted per IP before the signature is checked, so junk cannot be used to hammer the app.
        RateLimiter::for('paystack-webhook', fn (Request $request) => Limit::perMinute(max(1, (int) config('paystack.webhook_attempts_per_minute', 120)))->by($request->ip()));
    }
}
