<?php

namespace App\Providers;

use App\Services\DisplayTime;
use Carbon\Carbon as BaseCarbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

        // Counted per IP before the signature is checked, so junk cannot be used to hammer the app.
        RateLimiter::for('paystack-webhook', fn (Request $request) => Limit::perMinute(max(1, (int) config('paystack.webhook_attempts_per_minute', 120)))->by($request->ip()));
    }
}
