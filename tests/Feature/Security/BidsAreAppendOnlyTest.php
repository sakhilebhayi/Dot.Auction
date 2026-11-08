<?php

namespace Tests\Feature\Security;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The bid ledger cannot be rewritten, even by raw SQL: the database refuses it (migration 2026_11_09_000001). The
 * application's own writes (insert, flip is_winning, cascade with a never-opened draft) keep working.
 */
class BidsAreAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function bid(?Auction $auction = null, string $amount = '120.00'): Bid
    {
        $auction ??= Auction::factory()->create();

        return Bid::forceCreate(['auction_id' => $auction->id, 'bidder_id' => User::factory()->create()->id, 'amount' => $amount, 'is_winning' => true]);
    }

    private function refuses(callable $statement): void
    {
        try {
            DB::transaction($statement); // a savepoint: PostgreSQL aborts the whole test transaction on an error
        } catch (\Throwable $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());

            return;
        }

        $this->fail('the database accepted a change to the bid ledger');
    }

    public function test_the_winning_flag_can_move_as_the_bid_placer_needs(): void
    {
        $bid = $this->bid();

        Bid::query()->whereKey($bid->id)->update(['is_winning' => false]);

        $this->assertFalse($bid->fresh()->is_winning);
    }

    public function test_an_amount_a_bidder_an_auction_or_a_timestamp_cannot_be_rewritten(): void
    {
        $bid = $this->bid();
        $other = User::factory()->create();
        $otherAuction = Auction::factory()->create();

        $this->refuses(fn () => Bid::query()->whereKey($bid->id)->update(['amount' => '1.00']));
        $this->refuses(fn () => DB::table('bids')->where('id', $bid->id)->update(['bidder_id' => $other->id]));
        $this->refuses(fn () => DB::table('bids')->where('id', $bid->id)->update(['auction_id' => $otherAuction->id]));
        $this->refuses(fn () => DB::table('bids')->where('id', $bid->id)->update(['created_at' => now()->subYear()]));
        $this->refuses(fn () => DB::table('bids')->where('id', $bid->id)->update(['is_auto_bid' => true]));
        $this->refuses(fn () => DB::statement('update bids set amount = amount - 1'));

        $this->assertSame('120.00', (string) $bid->fresh()->amount);
    }

    public function test_a_bid_cannot_be_deleted_while_its_auction_exists(): void
    {
        $bid = $this->bid();

        $this->refuses(fn () => $bid->delete());
        $this->refuses(fn () => DB::table('bids')->where('id', $bid->id)->delete());
        $this->refuses(fn () => DB::table('bids')->delete());

        $this->assertSame(1, Bid::count());
    }

    public function test_deleting_the_auction_itself_still_removes_its_bids(): void
    {
        $auction = Auction::factory()->create();
        $this->bid($auction);

        DB::table('auctions')->where('id', $auction->id)->delete();

        $this->assertSame(0, Bid::count());
    }

    public function test_no_application_code_edits_or_deletes_a_bid(): void
    {
        // A static look at the places a bid could be changed from: only BidPlacer / BuyNowPurchaser create one and only
        // the is_winning flag is ever updated. Anything else in app/ that touches Bid writes is a finding.
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'))) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            if (preg_match('/Bid::[a-zA-Z]+\([^;]*->(delete|forceDelete|truncate)\(|bids\(\)->(delete|update)|table\([\'"]bids[\'"]\)[^;]*->(update|delete|truncate)\(/s', $code)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
        $this->assertSame([], $offenders);
    }
}
