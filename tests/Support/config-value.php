<?php

/*
 * Prints config(<key>) as JSON for the environment this process was started with, e.g.
 *   APP_URL=https://auction.example.test php tests/Support/config-value.php session.secure
 * Used by Security\CookieAndSessionTest to prove how a setting resolves from the environment. Not autoloaded.
 */

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo json_encode(config($argv[1] ?? '')), "\n";
