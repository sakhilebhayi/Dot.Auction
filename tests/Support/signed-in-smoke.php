<?php

/*
 * Opens every authenticated page as the right signed-in person, in-process, against the application exactly as
 * production boots it: routes, config and events must ALL be cached or this refuses to run. Drive it with
 * tests/Support/signed-in-smoke.sh, which builds a throwaway SQLite database, caches, runs this, and cleans up.
 *
 *   php tests/Support/signed-in-smoke.php
 *
 * Exit code 0 when every page answers 200 and no authenticated route was left unlisted.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Tests\Support\SignedInSmoke;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

foreach (['routes' => $app->routesAreCached(), 'config' => $app->configurationIsCached(), 'events' => $app->eventsAreCached()] as $what => $cached) {
    if (! $cached) {
        fwrite(STDERR, "REFUSING TO RUN: {$what} are not cached. Use tests/Support/signed-in-smoke.sh.\n");
        exit(2);
    }
}

Artisan::call('migrate', ['--force' => true]);
$host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

$smoke = new SignedInSmoke;
$people = $smoke->seed();
$kernel = $app->make(HttpKernel::class);

$failures = 0;
printf("%-9s %-52s %s\n", 'AS', 'GET', 'STATUS');

foreach ($smoke->pages() as $page) {
    // A new browser per page: no session left over from the previous person (the array driver lives in this process).
    $app['session.store']->flush();
    Auth::forgetGuards();
    Auth::shouldUse('web');
    Auth::guard('web')->setUser($people[$page['who']]);

    $request = Request::create('https://'.$host.$page['uri'], 'GET');
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $kernel->terminate($request, $response);

    $ok = $status === 200;
    $failures += $ok ? 0 : 1;
    printf("%-9s %-52s %d%s\n", $page['who'], $page['uri'], $status, $ok ? '' : '   <-- FAIL');
}

// Host pinning (TrustHosts, F-19): an unknown Host is refused, a health probe is not.
foreach ([['https://evil.smoke.test/login', 400], ['https://evil.smoke.test/up', 200]] as [$url, $expected]) {
    $app['session.store']->flush();
    Auth::forgetGuards();
    $request = Request::create($url, 'GET');
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $kernel->terminate($request, $response);
    $ok = $status === $expected;
    $failures += $ok ? 0 : 1;
    printf("%-9s %-52s %d%s\n", 'anyone', $url, $status, $ok ? "  (expected {$expected})" : "   <-- FAIL (expected {$expected})");
}

$uncovered = $smoke->uncoveredRoutes();
foreach ($uncovered as $uri) {
    fwrite(STDERR, "UNLISTED authenticated route: {$uri} (open it in SignedInSmoke::pages() or explain it in SKIPPED)\n");
}

$total = count($smoke->pages());
printf("\n%d pages, %d failed, %d unlisted authenticated routes (routes, config and events cached)\n", $total, $failures, count($uncovered));

exit($failures === 0 && $uncovered === [] ? 0 : 1);
