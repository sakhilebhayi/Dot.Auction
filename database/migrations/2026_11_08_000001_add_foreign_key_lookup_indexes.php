<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL does not index foreign keys on its own, and these columns are looked up on every dashboard, seller
 * list, settlement list, "ending soon" run and (through ON DELETE CASCADE) every account deletion. Found by running
 * EXPLAIN on a 5k-auction / 200k-bid dataset: each was a sequential scan. Plain additive indexes; every one is
 * skipped if it already exists so the migration is safe on the shared database.
 */
return new class extends Migration
{
    /**
     * @var array<string, array<string, array<int, string>>>
     */
    private const INDEXES = [
        'auctions' => [
            'auctions_seller_id_index' => ['seller_id'],
            'auctions_category_id_index' => ['category_id'],
        ],
        'bids' => [
            'bids_bidder_id_index' => ['bidder_id'],
            // Bid history is "newest first, 10 rows" for one auction; this lets that read stop after 10 rows.
            'bids_auction_id_created_at_id_index' => ['auction_id', 'created_at', 'id'],
        ],
        'watchlists' => [
            'watchlists_auction_id_index' => ['auction_id'],
        ],
        'auction_settlements' => [
            'auction_settlements_winner_id_index' => ['winner_id'],
            'auction_settlements_seller_id_index' => ['seller_id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
