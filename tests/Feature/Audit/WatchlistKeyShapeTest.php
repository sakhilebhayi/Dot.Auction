<?php

namespace Tests\Feature\Audit;

use App\Models\Auction;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `watchlists` is keyed by (user_id, auction_id) and has no `id` column. Eloquent's default is an incrementing `id`,
 * so on PostgreSQL (production) every insert asked the database to RETURN "id" and failed: "watch this auction" and
 * therefore every ending-soon notice never worked there, while all of it passed on SQLite.
 */
class WatchlistKeyShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_model_does_not_expect_an_id_column(): void
    {
        $this->assertFalse(Schema::hasColumn('watchlists', 'id'));
        $this->assertFalse((new Watchlist)->getIncrementing());
    }

    public function test_adding_a_watch_never_asks_the_database_to_return_an_id(): void
    {
        $user = User::factory()->create();
        $auction = Auction::factory()->create();
        $inserts = [];
        DB::listen(function (QueryExecuted $query) use (&$inserts) {
            if (str_starts_with(strtolower($query->sql), 'insert into "watchlists"')) {
                $inserts[] = $query->sql;
            }
        });

        Watchlist::create(['user_id' => $user->id, 'auction_id' => $auction->id]);

        $this->assertCount(1, $inserts);
        $this->assertStringNotContainsString('returning', strtolower($inserts[0]));
        $this->assertSame(1, Watchlist::withoutGlobalScopes()->where('user_id', $user->id)->count());
    }
}
