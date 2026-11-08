<?php

namespace Tests\Feature\R42;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

/**
 * F-02 backstop: a user row that owns an auction, a bid or a settlement cannot be deleted from underneath them.
 */
class UserForeignKeysTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    private const KEYS = ['auctions' => ['seller_id'], 'bids' => ['bidder_id'], 'auction_settlements' => ['seller_id', 'winner_id']];

    private function action(string $table, string $column): ?string
    {
        foreach (Schema::getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column]) {
                return strtolower((string) $key['on_delete']);
            }
        }

        return null;
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_08_000003_restrict_user_foreign_keys_on_money_records.php');
    }

    public function test_the_user_keys_on_money_records_restrict(): void
    {
        foreach (self::KEYS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertSame('restrict', $this->action($table, $column), "$table.$column");
            }
        }
    }

    public function test_a_hand_run_delete_of_a_user_with_records_is_refused_and_nothing_cascades(): void
    {
        [$settlement, $winner, $seller] = $this->settlement();
        $bid = $settlement->auction->bids()->first() ?? Bid::query()->first();

        foreach ([$winner, $seller] as $user) {
            try {
                DB::transaction(fn () => DB::table('users')->where('id', $user->id)->delete()); // a savepoint, for PostgreSQL
                $this->fail('the delete should have been refused');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNotNull($settlement->fresh());
        $this->assertNotNull($bid->fresh());
        $this->assertNotNull(Auction::find($settlement->auction_id));
    }

    public function test_a_user_with_nothing_to_protect_can_still_be_deleted(): void
    {
        $user = User::factory()->create();

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertNull($user->fresh());
    }

    public function test_the_migration_is_reversible_and_idempotent_in_the_default_database(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite rebuilds a table to change a key, and PRAGMA foreign_keys cannot be switched off inside the
            // transaction RefreshDatabase wraps this test in. The real run is covered by the file database test below.
            $this->markTestSkipped('Covered on SQLite by test_on_sqlite_existing_rows_survive_the_real_migration.');
        }

        [$settlement] = $this->settlement();
        $migration = $this->migration();

        $migration->down();
        $this->assertSame('cascade', $this->action('bids', 'bidder_id'));
        $this->assertSame('cascade', $this->action('auction_settlements', 'winner_id'));

        $migration->up();
        $migration->up(); // a second run changes nothing

        foreach (self::KEYS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertSame('restrict', $this->action($table, $column), "$table.$column");
            }
        }

        $this->assertNotNull($settlement->fresh());
        $this->assertSame(1, Bid::count());
    }

    /**
     * The migration as `php artisan migrate` runs it, against a SQLite FILE (outside any test transaction) that holds
     * a paid sale: the table rebuild must neither lose a row nor cascade through the settlement.
     */
    public function test_on_sqlite_existing_rows_survive_the_real_migration(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fk').'.sqlite';
        touch($file);
        config(['database.connections.fk_file' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]]);
        $path = 'database/migrations/2026_11_08_000003_restrict_user_foreign_keys_on_money_records.php';

        try {
            $this->artisan('migrate', ['--database' => 'fk_file', '--force' => true])->assertSuccessful();
            $this->artisan('migrate:rollback', ['--database' => 'fk_file', '--path' => $path, '--force' => true])->assertSuccessful();

            $db = DB::connection('fk_file');
            $now = now()->toDateTimeString();
            foreach ([1, 2] as $id) {
                $db->table('users')->insert(['id' => $id, 'name' => "User $id", 'email' => "u$id@example.test", 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
            }
            $db->table('auctions')->insert(['id' => 1, 'seller_id' => 1, 'title' => 'Lot', 'starting_price' => 100, 'current_price' => 150, 'bid_increment' => 10, 'status' => 'ended', 'starts_at' => $now, 'ends_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $db->table('bids')->insert(['id' => 1, 'auction_id' => 1, 'bidder_id' => 2, 'amount' => 150, 'is_winning' => true, 'created_at' => $now, 'updated_at' => $now]);
            $db->table('auction_settlements')->insert(['id' => '0192b0a0-0000-7000-8000-000000000001', 'auction_id' => 1, 'winning_bid_id' => 1, 'seller_id' => 1, 'winner_id' => 2, 'amount_cents' => 15000, 'currency' => 'ZAR', 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now]);

            $this->artisan('migrate', ['--database' => 'fk_file', '--force' => true])->assertSuccessful();

            $this->assertSame([1, 1, 1, 2], [$db->table('auctions')->count(), $db->table('bids')->count(), $db->table('auction_settlements')->count(), $db->table('users')->count()]);

            // The rebuild must not have turned the partial "one winner per auction" index into a plain unique one.
            $db->table('bids')->insert(['id' => 2, 'auction_id' => 1, 'bidder_id' => 1, 'amount' => 120, 'is_winning' => false, 'created_at' => $now, 'updated_at' => $now]);
            $db->table('bids')->insert(['id' => 3, 'auction_id' => 1, 'bidder_id' => 1, 'amount' => 130, 'is_winning' => false, 'created_at' => $now, 'updated_at' => $now]);
            try {
                $db->table('bids')->insert(['id' => 4, 'auction_id' => 1, 'bidder_id' => 1, 'amount' => 140, 'is_winning' => true, 'created_at' => $now, 'updated_at' => $now]);
                $this->fail('a second winning bid on one auction must still be refused');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }

            $keys = collect($db->getSchemaBuilder()->getForeignKeys('bids'))->firstWhere('columns', ['bidder_id']);
            $this->assertSame('restrict', strtolower($keys['on_delete']));

            try {
                $db->table('users')->where('id', 2)->delete();
                $this->fail('deleting the winner should be refused');
            } catch (QueryException) {
                $this->assertSame(1, $db->table('auction_settlements')->count());
            }
        } finally {
            DB::purge('fk_file');
            @unlink($file);
        }
    }
}
