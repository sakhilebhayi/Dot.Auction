<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Found by EXPLAIN on a 5k-auction / 312k-bid / 150k-notification PostgreSQL dataset (R-42 performance pass).
 *
 *  - notifications: the bell counts a person's unread notifications on EVERY page and every 30-second poll. The only index
 *    was (notifiable_type, notifiable_id), so a person with 25,000 notifications scanned all of them for each page (6 to
 *    14 ms, measured). A partial index on the unread rows is tiny and answers the count from its own few entries. The
 *    inbox and the bell's "latest" lists sort by time, so a time-ordered index serves them without a sort.
 *  - bids: "the lots I bid on", their top bid and "do I lead" (My bids, the lot cards' You lead / Outbid word, the
 *    dashboard) look up by bidder and then by auction; (bidder_id, auction_id, amount) answers all three from the index.
 *
 * Plain additive indexes, each skipped if it already exists (the migration is safe to run twice and on a shared database).
 * They are written as SQL so the partial index is portable (PostgreSQL and SQLite both support it).
 */
return new class extends Migration
{
    /**
     * @var array<string, string> index name => definition after "ON"
     */
    private const INDEXES = [
        'notifications_unread_index' => 'notifications (notifiable_type, notifiable_id) WHERE read_at IS NULL',
        'notifications_notifiable_created_index' => 'notifications (notifiable_type, notifiable_id, created_at)',
        'bids_bidder_auction_amount_index' => 'bids (bidder_id, auction_id, amount)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
