<?php

/*
 * Prints "METHOD /uri" for every registered route in the environment given by APP_ENV, then exits. Used by
 * RouteSweepTest to prove that the /dev routes are not registered outside local and testing. Not autoloaded.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

foreach (Route::getRoutes()->getRoutes() as $route) {
    foreach ($route->methods() as $method) {
        if ($method !== 'HEAD') {
            echo $method.' /'.ltrim($route->uri(), '/'), "\n";
        }
    }
}
