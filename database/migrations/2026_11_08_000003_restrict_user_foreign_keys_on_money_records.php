<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-42 F-02. Deleting a user used to cascade through other people's records: a buyer who deleted their account
 * deleted the paid settlement, a seller who did so deleted every bid placed on their auctions. These keys now
 * RESTRICT: a user row that still owns an auction, a bid or a settlement cannot be deleted at all. The application
 * never deletes such a user; it anonymises them (App\Services\Auctions\AccountEraser), so this is the backstop for
 * any other code path or a hand-run DELETE.
 *
 * Portable: the keys are found by column and only changed when they are not RESTRICT already (so a re-run is a
 * no-op), on PostgreSQL by dropping and re-adding the constraint, on SQLite by the table rebuild Laravel performs.
 * Existing rows already satisfy the constraint, so nothing can fail on existing data. `down()` restores CASCADE.
 *
 * Deliberately unchanged: watchlists.user_id and the team tables stay CASCADE (a person's own, private rows), and
 * auction_settlements.winning_bid_id stays CASCADE (it follows its auction, and bids themselves are now protected from
 * the user side).
 */
return new class extends Migration
{
    /**
     * @var array<string, array<int, string>> table => columns that point at users.id
     */
    private const KEYS = [
        'auctions' => ['seller_id'],
        'bids' => ['bidder_id'],
        'auction_settlements' => ['seller_id', 'winner_id'],
    ];

    public function up(): void
    {
        $this->setAction('restrict');
    }

    public function down(): void
    {
        $this->setAction('cascade');
    }

    private function setAction(string $action): void
    {
        foreach (self::KEYS as $table => $columns) {
            foreach ($columns as $column) {
                $current = $this->currentAction($table, $column);

                if ($current === null || $current === $action) {
                    continue;
                }

                Schema::table($table, function (Blueprint $t) use ($column, $action) {
                    $t->dropForeign([$column]);

                    $foreign = $t->foreign($column)->references('id')->on('users');
                    $action === 'restrict' ? $foreign->restrictOnDelete() : $foreign->cascadeOnDelete();
                });
            }
        }

        $this->restoreWinnerIndexOnSqlite();
    }

    /**
     * SQLite changes a foreign key by rebuilding the table, and the rebuild recreates every index it knows about as a
     * plain one. `bids_one_winner_per_auction` is a PARTIAL unique index (one is_winning row per auction) made with raw
     * SQL, so the rebuild would turn it into UNIQUE(auction_id) and nobody could place a second bid. Put it back.
     * (PostgreSQL alters the constraint in place and never touches indexes.)
     */
    private function restoreWinnerIndexOnSqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $sql = DB::selectOne("select sql from sqlite_master where type = 'index' and name = 'bids_one_winner_per_auction'")?->sql;

        if ($sql !== null && stripos($sql, ' where ') !== false) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS bids_one_winner_per_auction');
        DB::statement('CREATE UNIQUE INDEX bids_one_winner_per_auction ON bids (auction_id) WHERE is_winning = 1');
    }

    /**
     * The ON DELETE action of the foreign key on $table.$column ('cascade', 'restrict', 'no action', 'set null'),
     * or null when there is none.
     */
    private function currentAction(string $table, string $column): ?string
    {
        foreach (Schema::getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column]) {
                return strtolower((string) $key['on_delete']);
            }
        }

        return null;
    }
};
