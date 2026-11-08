<?php

/*
 * Child process for ParallelBiddingPostgresTest: boots the application, waits for a shared start time, then places
 * ONE bid or Buy Now purchase and prints the outcome as a line of JSON.
 *
 *   php tests/Support/parallel-bid.php <auctionId> <userId> <bid|buy> <amount> <startAtMicrotime> [submissionToken]
 *
 * Not a test and not autoloaded; it only exists to give the database several real, simultaneous connections.
 */

use App\Models\User;
use App\Services\Auctions\BidPlacer;
use App\Services\Auctions\BidRejected;
use App\Services\Auctions\BuyNowPurchaser;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $auctionId, $userId, $mode, $amount, $startAt] = $argv;
$token = $argv[6] ?? null;
$user = User::findOrFail((int) $userId);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $bid = $mode === 'buy'
        ? $app->make(BuyNowPurchaser::class)->buy((int) $auctionId, $user)
        : $app->make(BidPlacer::class)->place((int) $auctionId, $user, $amount, $token);

    echo json_encode(['ok' => true, 'user' => (int) $userId, 'mode' => $mode, 'bid' => $bid->id, 'amount' => (string) $bid->amount]), "\n";
} catch (BidRejected $e) {
    echo json_encode(['ok' => false, 'user' => (int) $userId, 'mode' => $mode, 'why' => $e->getMessage()]), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'user' => (int) $userId, 'mode' => $mode, 'crash' => get_class($e), 'why' => substr($e->getMessage(), 0, 200)]), "\n";
}
