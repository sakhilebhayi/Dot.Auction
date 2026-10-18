<?php

namespace App\Services\Auctions;

use RuntimeException;

/** A settlement action refused for a reason the user can be told in plain words. */
class SettlementActionRejected extends RuntimeException {}
