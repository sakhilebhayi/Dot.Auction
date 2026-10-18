<?php

namespace App\Services\Auctions;

use RuntimeException;

/**
 * A bid failed a business rule (inactive window, below minimum, duplicate,
 * own auction...). The message is safe to show to the bidder as-is.
 */
class BidRejected extends RuntimeException {}
