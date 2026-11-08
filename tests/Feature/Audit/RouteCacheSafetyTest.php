<?php

namespace Tests\Feature\Audit;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `php artisan route:cache` (run by every deploy) stores the route table and then NEVER loads routes/*.php again.
 * Anything a route file does besides declaring routes silently disappears in production: a middleware alias, a
 * rate limiter, a model binding, a gate. Dot.Pulse went down on 2026-11-08 because of exactly that (an alias
 * defined in routes/web.php).
 *
 * Two guards: nothing but route declarations may live in routes/*.php, and every middleware, alias and named
 * rate limiter a route refers to must be resolvable from code that runs on every boot.
 */
class RouteCacheSafetyTest extends TestCase
{
    public function test_route_files_declare_routes_and_nothing_else(): void
    {
        $forbidden = [
            '->alias(' => 'a middleware alias',
            'aliasMiddleware(' => 'a middleware alias',
            'middlewareGroup(' => 'a middleware group',
            'prependMiddleware' => 'middleware registration',
            'pushMiddleware' => 'middleware registration',
            'RateLimiter::for' => 'a rate limiter',
            'Gate::define' => 'a gate',
            'Gate::policy' => 'a policy',
            'Gate::before' => 'a gate hook',
            'Gate::after' => 'a gate hook',
            'Route::bind(' => 'a route binding',
            'Route::model(' => 'a route binding',
            'Route::pattern(' => 'a route pattern',
            'Route::macro(' => 'a macro',
            'Event::listen' => 'an event listener',
            'Event::subscribe' => 'an event subscriber',
            'View::share' => 'shared view data',
            'View::composer' => 'a view composer',
            'config([' => 'a config change',
            'Config::set' => 'a config change',
            'app()->singleton' => 'a container binding',
            'app()->bind' => 'a container binding',
            '$this->app->' => 'a container binding',
            'Blade::' => 'a Blade directive',
            'Validator::extend' => 'a validation rule',
            'Schedule::' => 'a schedule (only routes/console.php may schedule)',
        ];

        foreach (glob(base_path('routes/*.php')) as $file) {
            $name = basename($file);
            $code = preg_replace(['!/\*.*?\*/!s', '!^\s*//.*$!m', '!^\s*#.*$!m'], '', (string) file_get_contents($file));

            foreach ($forbidden as $needle => $what) {
                if ($name === 'console.php' && $needle === 'Schedule::') {
                    continue;
                }

                $this->assertStringNotContainsString(
                    $needle,
                    $code,
                    "routes/{$name} registers {$what}. route:cache skips this file, so it would not exist in production. Move it to bootstrap/app.php or a service provider.",
                );
            }
        }
    }

    public function test_every_middleware_a_route_names_resolves_without_the_route_files(): void
    {
        $router = $this->app['router'];
        $known = array_keys($router->getMiddleware());
        $groups = array_keys($router->getMiddlewareGroups());
        $problems = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }

                [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, null);

                if (class_exists($name) || in_array($name, $known, true) || in_array($name, $groups, true)) {
                    // A named rate limiter ("throttle:paystack-webhook") must be registered by a provider.
                    if ($name === 'throttle' && $parameters !== null && ! ctype_digit(explode(',', $parameters)[0])) {
                        if (RateLimiter::limiter($parameters) === null) {
                            $problems[] = $route->uri().' uses the rate limiter "'.$parameters.'" which no provider registers';
                        }
                    }

                    continue;
                }

                $problems[] = $route->uri().' uses the middleware "'.$middleware.'" which nothing registers';
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_the_operator_alias_and_the_webhook_limiter_come_from_code_that_runs_on_every_boot(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $provider = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertStringContainsString("'operator' =>", $bootstrap);
        $this->assertStringContainsString("RateLimiter::for('paystack-webhook'", $provider);
        $this->assertNotNull(RateLimiter::limiter('paystack-webhook'));
    }
}
