<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-42 security. A bid is evidence. The application only ever INSERTs a bid and flips its `is_winning` flag; no code path
 * edits an amount or removes a bid. This makes the database say the same thing, so a bug, a hand-run statement or a
 * compromised account with write access cannot quietly rewrite the history of an auction:
 *
 *  - UPDATE may change `is_winning` and `updated_at` only. `amount`, `bidder_id`, `auction_id`, `created_at` and
 *    `is_auto_bid` raise an error.
 *  - DELETE is refused while the bid's auction still exists. The one legitimate removal is the cascade when the auction
 *    itself is deleted (a draft that was never open), where the parent row is already gone.
 *
 * PostgreSQL and SQLite only (the two databases this app runs on); any other driver is left alone. On SQLite note that
 * a later migration which rebuilds the `bids` table drops these triggers: add them again there (Security\BidsAreAppendOnlyTest
 * fails if that happens).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bids')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION bids_are_append_only() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NEW.amount IS DISTINCT FROM OLD.amount
            OR NEW.bidder_id IS DISTINCT FROM OLD.bidder_id
            OR NEW.auction_id IS DISTINCT FROM OLD.auction_id
            OR NEW.created_at IS DISTINCT FROM OLD.created_at
            OR NEW.is_auto_bid IS DISTINCT FROM OLD.is_auto_bid THEN
            RAISE EXCEPTION 'bids are append-only: only is_winning may change' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        RETURN NEW;
    END IF;

    IF EXISTS (SELECT 1 FROM auctions WHERE id = OLD.auction_id) THEN
        RAISE EXCEPTION 'bids are append-only: a bid cannot be deleted while its auction exists' USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    RETURN OLD;
END;
$$ LANGUAGE plpgsql
SQL);
            DB::unprepared('DROP TRIGGER IF EXISTS bids_append_only_update ON bids');
            DB::unprepared('DROP TRIGGER IF EXISTS bids_append_only_delete ON bids');
            DB::unprepared('CREATE TRIGGER bids_append_only_update BEFORE UPDATE ON bids FOR EACH ROW EXECUTE FUNCTION bids_are_append_only()');
            DB::unprepared('CREATE TRIGGER bids_append_only_delete BEFORE DELETE ON bids FOR EACH ROW EXECUTE FUNCTION bids_are_append_only()');

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS bids_append_only_update');
            DB::unprepared('DROP TRIGGER IF EXISTS bids_append_only_delete');
            DB::unprepared(<<<'SQL'
CREATE TRIGGER bids_append_only_update BEFORE UPDATE ON bids
WHEN NEW.amount IS NOT OLD.amount OR NEW.bidder_id IS NOT OLD.bidder_id OR NEW.auction_id IS NOT OLD.auction_id
  OR NEW.created_at IS NOT OLD.created_at OR NEW.is_auto_bid IS NOT OLD.is_auto_bid
BEGIN
    SELECT RAISE(ABORT, 'bids are append-only: only is_winning may change');
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER bids_append_only_delete BEFORE DELETE ON bids
WHEN EXISTS (SELECT 1 FROM auctions WHERE id = OLD.auction_id)
BEGIN
    SELECT RAISE(ABORT, 'bids are append-only: a bid cannot be deleted while its auction exists');
END
SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS bids_append_only_update ON bids');
            DB::unprepared('DROP TRIGGER IF EXISTS bids_append_only_delete ON bids');
            DB::unprepared('DROP FUNCTION IF EXISTS bids_are_append_only()');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS bids_append_only_update');
            DB::unprepared('DROP TRIGGER IF EXISTS bids_append_only_delete');
        }
    }
};
