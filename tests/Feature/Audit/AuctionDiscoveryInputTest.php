<?php

namespace Tests\Feature\Audit;

use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Browsing is the only public-ish surface that takes free text from the query string. On PostgreSQL (production)
 * LIKE is case sensitive and a non-numeric category id is a type error, neither of which SQLite shows.
 */
class AuctionDiscoveryInputTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    public function test_search_ignores_letter_case(): void
    {
        Auction::factory()->create(['title' => 'Signed Guitar']);
        Auction::factory()->create(['title' => 'Antique Clock']);

        $this->actingAs($this->viewer())->get(route('auctions.index', ['q' => 'guitar']))
            ->assertOk()->assertSee('Signed Guitar')->assertDontSee('Antique Clock');

        $this->actingAs($this->viewer())->get(route('auctions.index', ['q' => 'ANTIQUE']))
            ->assertOk()->assertSee('Antique Clock')->assertDontSee('Signed Guitar');
    }

    public function test_search_treats_percent_and_underscore_as_plain_characters(): void
    {
        Auction::factory()->create(['title' => 'Fifty percent off lot']);
        Auction::factory()->create(['title' => '100% Cotton Shirt']);
        Auction::factory()->create(['title' => 'Item_One']);
        Auction::factory()->create(['title' => 'ItemXOne']);

        $viewer = $this->viewer();

        $this->actingAs($viewer)->get(route('auctions.index', ['q' => '100%']))
            ->assertOk()->assertSee('100% Cotton Shirt')->assertDontSee('Fifty percent off lot');

        $this->actingAs($viewer)->get(route('auctions.index', ['q' => 'Item_One']))
            ->assertOk()->assertSee('Item_One')->assertDontSee('ItemXOne');

        $this->actingAs($viewer)->get(route('auctions.index', ['q' => '%']))
            ->assertOk()->assertSee('100% Cotton Shirt')->assertDontSee('Fifty percent off lot');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileQueries(): array
    {
        return [
            'non-numeric category' => ['category=abc'],
            'category far too big' => ['category=99999999999999999999'],
            'negative category' => ['category=-1'],
            'category array' => ['category[]=1&category[]=2'],
            'category nested' => ['category[a][b]=1'],
            'search array' => ['q[]=x'],
            'status array' => ['status[]=active'],
            'status junk' => ['status=%00%27%22'],
            'page junk' => ['page=abc'],
            'page negative' => ['page=-3'],
            'page huge' => ['page=99999999999999999999'],
            'page array' => ['page[]=1'],
            'very long search' => ['q='.'a'.'bcdefghij'.'%25'.'x'],
        ];
    }

    #[DataProvider('hostileQueries')]
    public function test_a_malformed_query_string_still_renders_the_page(string $query): void
    {
        AuctionCategory::factory()->create();
        Auction::factory()->create();

        $this->actingAs($this->viewer())->get('/auctions?'.$query)->assertOk();
    }

    public function test_an_unknown_category_id_shows_no_results_rather_than_an_error(): void
    {
        Auction::factory()->create(['title' => 'Only Lot']);

        $this->actingAs($this->viewer())->get(route('auctions.index', ['category' => 987654]))
            ->assertOk()->assertDontSee('Only Lot');
    }
}
