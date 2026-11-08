<?php

namespace Tests\Feature\R42;

use App\Actions\Jetstream\DeleteUser;
use App\Models\Auction;
use App\Models\AuctionImage;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\SettlementPayment;
use App\Models\Team;
use App\Models\User;
use App\Models\Watchlist;
use App\Notifications\AuctionEndingSoonNotification;
use App\Services\Auctions\AccountDeletionBlocked;
use App\Services\Auctions\AccountEraser;
use App\Services\Auctions\AuctionSettler;
use App\Services\Auctions\BidPlacer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Http\Livewire\DeleteUserForm;
use Livewire\Livewire;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

/**
 * F-02: leaving never takes other people's records with it. Blocked while there are live obligations, otherwise
 * anonymised; bids, auctions and settlements stay and keep working.
 */
class AccountDeletionTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    private function leave(User $user): void
    {
        app(DeleteUser::class)->delete($user->fresh());
    }

    private function assertBlocked(User $user, string $fragment): void
    {
        try {
            $this->leave($user);
            $this->fail('the account should not have been deletable');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($fragment, $e->errors()['password'][0]);
            $this->assertStringStartsWith('Your account cannot be deleted yet.', $e->errors()['password'][0]);
        }

        $this->assertFalse($user->fresh()->isAnonymised(), 'a blocked account must be left exactly as it was');
    }

    public function test_a_buyer_who_is_not_leading_leaves_and_the_auction_carries_on(): void
    {
        $auction = Auction::factory()->create(['title' => 'Atlas']);
        $leaver = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Nkosi']);
        $leader = User::factory()->create();
        app(BidPlacer::class)->place($auction, $leaver, '220.00');
        app(BidPlacer::class)->place($auction, $leader, '240.00');

        $this->leave($leaver);

        $leaver = $leaver->fresh();
        $this->assertSame('Former member', $leaver->name);
        $this->assertTrue($leaver->isAnonymised());
        $this->assertSame(2, Bid::count(), 'their bid is part of the auction and stays');
        $this->assertSame('Former member', $leaver->maskedName());

        // The auction still works: bids continue and the winner is still the leader.
        app(BidPlacer::class)->place($auction, User::factory()->create(), '260.00');
        $this->assertSame('260.00', $auction->fresh()->current_price);

        // The bid history names them as a former member.
        $this->actingAs($leader)->get(route('auctions.show', $auction))->assertOk()->assertSee('Former member');
    }

    public function test_the_leading_bidder_on_a_live_auction_cannot_leave(): void
    {
        $auction = Auction::factory()->create(['title' => 'Signed Guitar']);
        $leader = User::factory()->withPersonalTeam()->create();
        app(BidPlacer::class)->place($auction, $leader, '220.00');

        $this->assertBlocked($leader, 'You are the leading bidder on "Signed Guitar"');

        $this->assertSame(1, Bid::where('is_winning', true)->count());
    }

    public function test_a_leading_bidder_whose_auction_is_waiting_for_the_sellers_reserve_decision_cannot_leave(): void
    {
        $auction = Auction::factory()->create(['reserve_price' => '500.00', 'title' => 'Vase', 'ends_at' => now()->addMinutes(30)]);
        $leader = User::factory()->create();
        app(BidPlacer::class)->place($auction, $leader, '220.00');
        $this->travel(2)->hours();
        app(AuctionSettler::class)->settle($auction->id);
        $this->assertSame('pending_review', $auction->fresh()->status);

        $this->assertBlocked($leader, '"Vase"');
    }

    public function test_a_seller_with_bids_on_a_live_auction_cannot_leave_and_nothing_is_lost(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $auction = Auction::factory()->create(['seller_id' => $seller->id, 'title' => 'Camera']);
        app(BidPlacer::class)->place($auction, User::factory()->create(), '220.00');

        $this->assertBlocked($seller, 'Your listing "Camera" is still live');

        $this->assertSame(1, Auction::count());
        $this->assertSame(1, Bid::count());
    }

    public function test_a_seller_whose_sale_is_paid_can_leave_and_the_auction_bids_and_settlement_stay(): void
    {
        [$settlement, $winner, $seller] = $this->settlement(settlement: ['status' => AuctionSettlement::PAID, 'paid_at' => now()]);
        $auctionId = $settlement->auction_id;

        $this->leave($seller);

        $this->assertTrue($seller->fresh()->isAnonymised());
        $this->assertNotNull(Auction::find($auctionId), 'the auction stays');
        $this->assertSame(1, Bid::count(), 'the bids placed on it stay');
        $this->assertSame('paid', $settlement->fresh()->status);
        $this->assertSame($seller->id, $settlement->fresh()->seller_id);

        // The buyer can still see their purchase.
        $this->actingAs($winner)->get(route('settlements.show', $settlement))->assertOk();
    }

    public function test_a_winner_with_a_paid_settlement_can_leave_and_the_payment_record_survives(): void
    {
        [$settlement, $winner, $seller] = $this->settlement(settlement: ['status' => AuctionSettlement::PAID, 'paid_at' => now()]);
        $payment = $this->attempt($settlement, ['status' => SettlementPayment::PAID, 'paid_at' => now(), 'provider_transaction_id' => 'T-1']);

        $this->leave($winner);

        $this->assertTrue($winner->fresh()->isAnonymised());
        $this->assertSame('paid', $settlement->fresh()->status);
        $this->assertSame($winner->id, $settlement->fresh()->winner_id);
        $this->assertSame($settlement->id, $payment->fresh()->settlement_id, 'the payment must stay attached to its sale');
        $this->assertSame(1, Bid::where('is_winning', true)->count());

        // The seller still opens the sale, which now names a former member.
        $this->actingAs($seller)->get(route('settlements.show', $settlement))->assertOk();
    }

    public function test_an_unpaid_sale_blocks_both_the_buyer_and_the_seller(): void
    {
        [$settlement, $winner, $seller] = $this->settlement();
        $settlement->auction->forceFill(['title' => 'Compass'])->save();

        $this->assertBlocked($winner, 'The sale of "Compass" has not been paid');
        $this->assertBlocked($seller, 'The sale of "Compass" has not been paid');

        $settlement->forceFill(['status' => AuctionSettlement::PAYMENT_OVERDUE])->save();
        $this->assertBlocked($winner, '"Compass"');

        $settlement->forceFill(['status' => AuctionSettlement::CANCELLED, 'cancelled_at' => now()])->save();
        $this->leave($winner);
        $this->assertTrue($winner->fresh()->isAnonymised());
    }

    public function test_a_payment_waiting_for_an_operator_blocks_even_a_paid_sale(): void
    {
        [$settlement, $winner] = $this->settlement(settlement: ['status' => AuctionSettlement::PAID, 'paid_at' => now()]);
        $settlement->auction->forceFill(['title' => 'Clock'])->save();
        $this->attempt($settlement, ['status' => SettlementPayment::UNMATCHED, 'reason' => 'duplicate_payment']);

        $this->assertBlocked($winner, 'A payment for "Clock" is waiting for an operator');
    }

    public function test_an_ended_auction_whose_settlement_is_not_recorded_yet_blocks_the_winner_and_the_seller(): void
    {
        $auction = Auction::factory()->ended()->create(['title' => 'Pending sale']);
        $winner = User::factory()->create();
        Bid::forceCreate(['auction_id' => $auction->id, 'bidder_id' => $winner->id, 'amount' => '210.00', 'is_winning' => true]);

        $this->assertBlocked($winner, '"Pending sale" is still being recorded');
        $this->assertBlocked($auction->seller, '"Pending sale" is still being recorded');
    }

    public function test_many_blockers_are_listed_with_a_count_not_dumped(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        Auction::factory()->count(5)->create(['seller_id' => $seller->id]);

        $this->assertBlocked($seller, 'and 2 more');
    }

    public function test_personal_data_is_removed_and_the_placeholder_can_never_receive_mail(): void
    {
        Storage::fake(AuctionImage::DISK);
        $user = User::factory()->withPersonalTeam()->create([
            'name' => 'Thandi Nkosi', 'email' => 'thandi@example.com',
            'two_factor_secret' => 'secret', 'two_factor_recovery_codes' => 'codes', 'two_factor_confirmed_at' => now(),
            'remember_token' => 'remember', 'profile_photo_path' => 'profile-photos/x.jpg',
        ]);
        $other = User::factory()->withPersonalTeam()->create();
        $other->personalTeam()->users()->attach($user, ['role' => 'editor']);
        $user->createToken('api');
        $user->notify(new AuctionEndingSoonNotification(Auction::factory()->create()));
        Watchlist::unguarded(fn () => DB::table('watchlists')->insert(['user_id' => $user->id, 'auction_id' => Auction::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]));
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $draft = Auction::factory()->draft()->create(['seller_id' => $user->id]);
        Storage::disk(AuctionImage::DISK)->put('x/d.jpg', 'jpeg');
        AuctionImage::create(['auction_id' => $draft->id, 'path' => 'x/d.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 4, 'width' => 1, 'height' => 1]);
        $oldPassword = $user->password;

        $this->leave($user);

        $user = $user->fresh();
        $this->assertSame('Former member', $user->name);
        $this->assertStringEndsWith('@deleted.invalid', $user->email);
        $this->assertStringNotContainsString('thandi', $user->email);
        $this->assertNotSame($oldPassword, $user->password);
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->remember_token);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->profile_photo_path);
        $this->assertNull($user->current_team_id);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(0, DB::table('watchlists')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('team_user')->where('user_id', $user->id)->count());
        $this->assertSame(0, Team::where('user_id', $user->id)->count(), 'their own teams go');
        $this->assertNull(Auction::find($draft->id), 'an unpublished draft goes');
        Storage::disk(AuctionImage::DISK)->assertMissing('x/d.jpg');
        $this->assertNotNull(Team::find($other->personalTeam()->id), 'other people\'s teams stay');
    }

    public function test_each_placeholder_email_is_unique_and_the_old_address_is_free_again(): void
    {
        $a = User::factory()->create(['email' => 'a@example.com']);
        $b = User::factory()->create(['email' => 'b@example.com']);

        $this->leave($a);
        $this->leave($b);

        $this->assertNotSame($a->fresh()->email, $b->fresh()->email);
        User::factory()->create(['email' => 'a@example.com']);
        $this->assertSame(1, User::where('email', 'a@example.com')->count());
    }

    public function test_the_account_deletion_form_shows_the_reasons_under_the_password_field(): void
    {
        $auction = Auction::factory()->create(['title' => 'Signed Guitar']);
        $leader = User::factory()->withPersonalTeam()->create();
        app(BidPlacer::class)->place($auction, $leader, '220.00');
        $this->actingAs($leader);

        Livewire::test(DeleteUserForm::class)
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors(['password'])
            ->assertSee('You are the leading bidder on "Signed Guitar"');

        $this->assertFalse($leader->fresh()->isAnonymised());
    }

    public function test_the_eraser_names_every_reason_for_a_person_with_several(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        Auction::factory()->create(['seller_id' => $seller->id, 'title' => 'Live lot']);
        $other = Auction::factory()->create(['title' => 'Other lot']);
        app(BidPlacer::class)->place($other, $seller, '220.00');

        $reasons = app(AccountEraser::class)->obstacles($seller);

        $this->assertCount(2, $reasons);
        $this->expectException(AccountDeletionBlocked::class);
        app(AccountEraser::class)->erase($seller);
    }

    public function test_no_code_path_still_deletes_a_user_row_that_owns_records(): void
    {
        [, $winner] = $this->settlement();

        $this->expectException(QueryException::class);
        DB::transaction(fn () => $winner->delete());
    }
}
