<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WINNER_INDEX = 'bids_one_winner_per_auction';

    public function up(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            $table->index(['auction_id', 'amount'], 'bids_auction_id_amount_index');
        });

        Schema::table('auctions', function (Blueprint $table) {
            $table->index(['status', 'ends_at'], 'auctions_status_ends_at_index');
        });

        $driver = DB::connection()->getDriverName();

        // A partial unique index is only available on SQLite and PostgreSQL.
        // MySQL/MariaDB have no equivalent: there the auction row lock taken
        // by App\Services\Auctions\BidPlacer is the sole guarantee.
        if (! in_array($driver, ['sqlite', 'pgsql'], true)) {
            return;
        }

        $this->demoteDuplicateWinners();

        $predicate = $driver === 'pgsql' ? 'is_winning = true' : 'is_winning = 1';
        DB::statement('CREATE UNIQUE INDEX '.self::WINNER_INDEX.' ON bids (auction_id) WHERE '.$predicate);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::WINNER_INDEX);

        Schema::table('auctions', function (Blueprint $table) {
            $table->dropIndex('auctions_status_ends_at_index');
        });

        Schema::table('bids', function (Blueprint $table) {
            $table->dropIndex('bids_auction_id_amount_index');
        });
    }

    /**
     * Rows written by the old non-atomic placeBid() can hold several
     * is_winning rows for one auction, which would make the unique index
     * fail to build. Keep the highest bid (latest id on a tie) as the
     * winner and demote the rest; the ledger rows themselves are untouched.
     */
    private function demoteDuplicateWinners(): void
    {
        $auctionIds = DB::table('bids')
            ->where('is_winning', true)
            ->groupBy('auction_id')
            ->havingRaw('count(*) > 1')
            ->pluck('auction_id');

        foreach ($auctionIds as $auctionId) {
            $keep = DB::table('bids')
                ->where('auction_id', $auctionId)
                ->where('is_winning', true)
                ->orderByDesc('amount')
                ->orderByDesc('id')
                ->value('id');

            DB::table('bids')
                ->where('auction_id', $auctionId)
                ->where('is_winning', true)
                ->where('id', '!=', $keep)
                ->update(['is_winning' => false]);
        }
    }
};
