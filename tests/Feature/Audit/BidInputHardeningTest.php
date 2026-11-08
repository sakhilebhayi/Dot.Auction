<?php

namespace Tests\Feature\Audit;

use App\Livewire\Auctions\BidPanel;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Services\Auctions\BidPlacer;
use App\Services\Auctions\BidRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R42 backend audit: BidPlacer must answer every possible amount with a BidRejected (a readable message) or an
 * accepted bid. Before the fix, non-finite and oversized amounts surfaced as ErrorException / a PostgreSQL
 * "numeric field overflow" QueryException (an HTTP 500 for the bidder).
 */
class BidInputHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function auction(): Auction
    {
        return Auction::factory()->create(['starting_price' => '100.00', 'current_price' => '100.00', 'bid_increment' => '5.00']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusableAmounts(): array
    {
        return [
            'float INF' => [INF],
            'float -INF' => [-INF],
            'float NAN' => [NAN],
            'huge exponent string' => ['1e308'],
            'huge float' => [1e308],
            'beyond int range' => ['999999999999999999999'],
            'more than decimal(12,2) can hold' => ['10000000000'],
            'one cent over the column' => ['10000000000.00'],
            'above the platform price ceiling' => ['100000001'],
            'exponent that lands above the ceiling' => ['1e15'],
            'negative' => [-5],
            'zero' => [0],
            'empty' => [''],
            'words' => ['abc'],
        ];
    }

    #[DataProvider('unusableAmounts')]
    public function test_an_unusable_amount_is_rejected_with_a_readable_message(mixed $amount): void
    {
        $auction = $this->auction();
        $bidder = User::factory()->create();

        try {
            app(BidPlacer::class)->place($auction, $bidder, $amount);
            $this->fail('The amount should have been rejected.');
        } catch (BidRejected $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(0, Bid::count());
        $this->assertEquals(100, $auction->fresh()->current_price);
    }

    public function test_the_highest_amount_a_seller_may_list_is_still_a_legal_bid(): void
    {
        $auction = Auction::factory()->create(['starting_price' => '100.00', 'current_price' => '100.00', 'bid_increment' => '5.00']);
        $bidder = User::factory()->create();
        $ceiling = (string) config('auction.listing.max_price');

        $bid = app(BidPlacer::class)->place($auction, $bidder, $ceiling);

        $this->assertSame((float) $ceiling, (float) $bid->amount);
    }

    public function test_the_bid_panel_survives_hostile_values_typed_into_the_amount_field(): void
    {
        $auction = $this->auction();
        $buyer = User::factory()->withPersonalTeam()->create();

        foreach (['1e308', '1e400', '99999999999', '-1', '0.001', 'abc', ''] as $typed) {
            Livewire::actingAs($buyer)
                ->test(BidPanel::class, ['auction' => $auction])
                ->set('bidAmount', $typed)
                ->call('placeBid')
                ->assertSet('success', null);
        }

        $this->assertSame(0, Bid::count());
    }
}
