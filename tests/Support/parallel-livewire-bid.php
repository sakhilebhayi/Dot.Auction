<?php

/*
 * Child process for Security\ParallelLivewireBiddingPostgresTest: boots the application, waits for a shared start time,
 * then drives the REAL bid panel (Livewire component -> BidPlacer / BuyNowPurchaser) as a signed-in user, once, and prints
 * what the panel said as a line of JSON.
 *
 *   php tests/Support/parallel-livewire-bid.php <auctionId> <userId> <bid|buy> <amount> <startAtMicrotime> [submissionToken]
 *
 * Not a test and not autoloaded. CACHE_STORE=database is set by the parent so the idempotency token is shared between
 * processes, as in production.
 */

use App\Livewire\Auctions\BidPanel;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Livewire\Livewire;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $auctionId, $userId, $mode, $amount, $startAt] = $argv;
$token = $argv[6] ?? null;
$user = User::findOrFail((int) $userId);
$auction = Auction::findOrFail((int) $auctionId);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $panel = Livewire::actingAs($user)->test(BidPanel::class, ['auction' => $auction]);

    if ($token !== null) {
        $panel->set('submissionToken', $token);
    }

    if ($mode === 'buy') {
        $panel->call('buyNow');
    } else {
        $panel->set('bidAmount', $amount)->call('placeBid');
    }

    echo json_encode([
        'ok' => $panel->get('success') !== null && $panel->get('error') === null,
        'user' => (int) $userId,
        'mode' => $mode,
        'success' => $panel->get('success'),
        'error' => $panel->get('error'),
    ]), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'user' => (int) $userId, 'mode' => $mode, 'crash' => get_class($e), 'why' => substr($e->getMessage(), 0, 200)]), "\n";
}
