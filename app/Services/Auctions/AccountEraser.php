<?php

namespace App\Services\Auctions;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\SettlementPayment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Jetstream\Contracts\DeletesTeams;

/**
 * "Delete my account" (R-42 F-02). A person leaving must never take other people's records with them: bids, auctions
 * and settlements are other people's evidence and the finance trail, so they stay and keep working. What leaves is the
 * person.
 *
 *  1. While the person has a live obligation the account cannot be deleted at all, with the reasons listed (obstacles()):
 *     a live, scheduled or reserve-pending listing of theirs; being the leading bidder on a live or reserve-pending
 *     auction; a sale (as buyer or seller) that is unpaid or overdue, or whose payment needs an operator's decision; an
 *     ended auction of theirs or that they won whose settlement has not been recorded yet.
 *  2. Otherwise the account is ANONYMISED, not deleted: name "Former member", e-mail replaced with a unique address on
 *     the reserved, unresolvable `.invalid` domain, a random password nobody knows, profile photo removed, API tokens,
 *     browser sessions, two-factor secrets, team memberships (and the teams they own), notifications and watchlist
 *     removed. Their unpublished drafts are deleted (nobody else has bid on them). Bids, published auctions and
 *     settlements stay, attached to the same user row, now showing "Former member".
 *
 * The foreign keys from bids, auctions and settlements to users are RESTRICT (migration 2026_11_08_000003), so any other
 * code path that tries to DELETE such a user fails loudly instead of cascading.
 */
class AccountEraser
{
    public function __construct(private readonly DeletesTeams $deletesTeams) {}

    /** The reserved TLD `.invalid` can never resolve (RFC 2606), so a placeholder address can never receive mail. */
    public const PLACEHOLDER_DOMAIN = 'deleted.invalid';

    public const FORMER_NAME = 'Former member';

    /** At most this many lot titles are named per reason, the rest are counted. */
    private const TITLES_SHOWN = 3;

    /**
     * Why this person cannot leave yet; empty when they can.
     *
     * @return array<int, string>
     */
    public function obstacles(User $user): array
    {
        $id = $user->getKey();
        $reasons = [];

        $listings = Auction::query()->where('seller_id', $id)->whereIn('status', ['active', 'pending_review'])->orderBy('id')->pluck('title');
        if ($listings->isNotEmpty()) {
            $reasons[] = 'Your listing '.$this->titles($listings).' is still live, scheduled, or waiting for your reserve decision. Cancel it, or let it end and be settled.';
        }

        $leading = Auction::query()
            ->whereIn('status', ['active', 'pending_review'])
            ->whereExists(fn ($q) => $q->from('bids')->whereColumn('bids.auction_id', 'auctions.id')->where('bids.bidder_id', $id)->where('bids.is_winning', true))
            ->orderBy('id')->pluck('title');
        if ($leading->isNotEmpty()) {
            $reasons[] = 'You are the leading bidder on '.$this->titles($leading).'. Wait until the auction ends.';
        }

        $unpaid = AuctionSettlement::query()
            ->where(fn ($q) => $q->where('winner_id', $id)->orWhere('seller_id', $id))
            ->whereIn('status', [AuctionSettlement::AWAITING_PAYMENT, AuctionSettlement::PAYMENT_OVERDUE])
            ->with('auction:id,title')->get()->pluck('auction.title');
        if ($unpaid->isNotEmpty()) {
            $reasons[] = 'The sale of '.$this->titles($unpaid).' has not been paid. It has to be paid or cancelled first.';
        }

        $held = AuctionSettlement::query()
            ->where(fn ($q) => $q->where('winner_id', $id)->orWhere('seller_id', $id))
            ->whereExists(fn ($q) => $q->from('settlement_payments')->whereColumn('settlement_payments.settlement_id', 'auction_settlements.id')->where('settlement_payments.status', SettlementPayment::UNMATCHED))
            ->with('auction:id,title')->get()->pluck('auction.title');
        if ($held->isNotEmpty()) {
            $reasons[] = 'A payment for '.$this->titles($held).' is waiting for an operator to resolve it.';
        }

        $unrecorded = Auction::query()
            ->where('status', 'ended')
            ->where('ends_at', '>=', now()->subDays(max(1, (int) config('auction.settlement_sweep.lookback_days', 7))))
            ->where(fn ($q) => $q->where('seller_id', $id)->orWhereExists(
                fn ($b) => $b->from('bids')->whereColumn('bids.auction_id', 'auctions.id')->where('bids.bidder_id', $id)->where('bids.is_winning', true)
            ))
            ->whereExists(fn ($q) => $q->from('bids')->whereColumn('bids.auction_id', 'auctions.id')->where('bids.is_winning', true))
            ->whereNotExists(fn ($q) => $q->from('auction_settlements')->whereColumn('auction_settlements.auction_id', 'auctions.id'))
            ->whereNotExists(fn ($q) => $q->from('reserve_not_met_proposals')->whereColumn('reserve_not_met_proposals.auction_id', 'auctions.id')->where('reserve_not_met_proposals.status', 'rejected'))
            ->orderBy('id')->pluck('title');
        if ($unrecorded->isNotEmpty()) {
            $reasons[] = 'The sale of '.$this->titles($unrecorded).' is still being recorded. Try again in a little while.';
        }

        return $reasons;
    }

    /**
     * Anonymise the person, or throw AccountDeletionBlocked. Checked again under a lock on the user row.
     *
     * @throws AccountDeletionBlocked
     */
    public function erase(User $user): void
    {
        DB::transaction(function () use ($user) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            $reasons = $this->obstacles($locked);
            if ($reasons !== []) {
                throw new AccountDeletionBlocked($reasons);
            }

            // Unpublished drafts can have no bids, no settlement and no watchers: they go (through the model, so the
            // photo files are removed once this commits).
            Auction::query()->where('seller_id', $locked->id)->where('status', 'draft')->get()->each->delete();

            $locked->teams()->detach();
            $locked->ownedTeams->each(fn (Team $team) => $this->deletesTeams->delete($team));

            $locked->deleteProfilePhoto();
            $locked->tokens()->delete();
            $locked->notifications()->delete();
            DB::table('watchlists')->where('user_id', $locked->id)->delete();

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $locked->id)->delete();
            }

            $locked->forceFill([
                'name' => self::FORMER_NAME,
                'email' => self::placeholderEmail($locked),
                'email_verified_at' => null,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'profile_photo_path' => null,
                'current_team_id' => null,
            ])->save();
        });
    }

    public static function placeholderEmail(User $user): string
    {
        return 'former-member-'.$user->getKey().'-'.Str::lower((string) Str::ulid()).'@'.self::PLACEHOLDER_DOMAIN;
    }

    /**
     * @param  Collection<int, string>  $titles
     */
    private function titles($titles): string
    {
        $shown = $titles->take(self::TITLES_SHOWN)->map(fn ($title) => '"'.$title.'"')->implode(', ');
        $more = $titles->count() - self::TITLES_SHOWN;

        return $more > 0 ? $shown." and {$more} more" : $shown;
    }
}
