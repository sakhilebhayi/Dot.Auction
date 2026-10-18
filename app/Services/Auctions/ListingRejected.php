<?php

namespace App\Services\Auctions;

use RuntimeException;

/**
 * A listing change was refused by a business rule (a bid exists, wrong
 * status, unsound terms). The message is safe to show to the seller.
 */
class ListingRejected extends RuntimeException {}
