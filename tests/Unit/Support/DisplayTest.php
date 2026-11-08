<?php

namespace Tests\Unit\Support;

use App\Models\Auction;
use App\Models\Bid;
use App\Support\Display;
use App\Support\Icons;
use Tests\TestCase;

/** The presentation helpers behind the design system (R-42 shell). */
class DisplayTest extends TestCase
{
    private function lot(string $status, array $over = []): Auction
    {
        $auction = new Auction(array_merge([
            'status' => $status,
            'starting_price' => '1000.00', 'current_price' => '1000.00', 'bid_increment' => '50.00',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(5), 'snipe_extensions' => 0,
        ], $over));
        $auction->setRelation('winningBid', null);

        return $auction;
    }

    public function test_money_parts_split_symbol_whole_and_cents_from_the_configured_currency(): void
    {
        config(['auction.currency_symbol' => 'R']);

        $this->assertSame(['symbol' => 'R', 'whole' => '1,234', 'cents' => '50', 'negative' => false], Display::moneyParts('1234.50'));
        $this->assertSame('05', Display::moneyParts(0.05)['cents']);
        $this->assertTrue(Display::moneyParts('-12.00')['negative']);
        $this->assertSame('0', Display::moneyParts(null)['whole']);

        config(['auction.currency_symbol' => '$']);
        $this->assertSame('$', Display::moneyParts('1.00')['symbol']);
        $this->assertSame(Auction::money('1234.5'), Display::money('1234.5'));
    }

    public function test_remaining_time_uses_the_same_thresholds_as_the_script(): void
    {
        $this->assertSame('2d 4h', Display::remaining(2 * 86400 + 4 * 3600 + 59));
        $this->assertSame('3h 05m', Display::remaining(3 * 3600 + 5 * 60 + 9));
        $this->assertSame('12m 05s', Display::remaining(12 * 60 + 5));
        $this->assertSame('45s', Display::remaining(45));
        $this->assertSame('0s', Display::remaining(-10));
        $this->assertSame([['3', 'h'], ['05', 'm']], Display::remainingParts(3 * 3600 + 5 * 60 + 9));
        $this->assertSame([['12', 'm'], ['05', 's']], Display::remainingParts(12 * 60 + 5));
        $this->assertSame([['45', 's']], Display::remainingParts(45));
    }

    public function test_times_are_shown_in_the_display_zone_not_utc(): void
    {
        config(['auction.display_timezone' => 'Africa/Johannesburg']);
        $utc = new \DateTimeImmutable('2026-11-14 16:30:00', new \DateTimeZone('UTC'));

        $this->assertSame('Sat 14 Nov, 18:30', Display::time($utc));
        $this->assertSame('18:30 SAST', Display::time($utc, 'H:i', true));
        $this->assertSame('', Display::time(null));
    }

    public function test_routes_that_do_not_exist_yet_link_to_a_harmless_hash(): void
    {
        $this->assertSame('#', Display::route('buyer.bids'));
        $this->assertSame('#', auction_route('buyer.watchlist'));
        $this->assertFalse(auction_route_exists('buyer.won'));
        $this->assertSame(route('dashboard'), auction_route('dashboard'));
        $this->assertTrue(auction_route_exists('dashboard'));
    }

    public function test_initials(): void
    {
        $this->assertSame('TM', Display::initials('Thandi Mokoena'));
        $this->assertSame('SN', Display::initials('  sipho   nkosi '));
        $this->assertSame('P', Display::initials('pieter'));
        $this->assertSame('?', Display::initials(''));
        $this->assertSame('?', Display::initials(null));
    }

    public function test_every_lot_state_is_derived_correctly_and_has_a_word_an_icon_and_a_tone(): void
    {
        $this->assertSame('draft', Display::lotState($this->lot('draft')));
        $this->assertSame('cancelled', Display::lotState($this->lot('cancelled')));
        $this->assertSame('pending', Display::lotState($this->lot('pending_review')), 'ended below the reserve is not "cancelled"');
        $this->assertSame('scheduled', Display::lotState($this->lot('active', ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)])));
        $this->assertSame('live', Display::lotState($this->lot('active')));
        $this->assertSame('ending', Display::lotState($this->lot('active', ['ends_at' => now()->addMinutes(20)])));
        $this->assertSame('unsold', Display::lotState($this->lot('ended', ['ends_at' => now()->subHour()])));

        $sold = $this->lot('ended', ['ends_at' => now()->subHour()]);
        $sold->setRelation('winningBid', new Bid);
        $this->assertSame('sold', Display::lotState($sold));

        foreach (['live', 'ending', 'scheduled', 'sold', 'unsold', 'pending', 'cancelled', 'extended', 'draft'] as $state) {
            $def = Display::statusVocabulary()[$state] ?? null;
            $this->assertNotNull($def, "$state is in the vocabulary");
            $this->assertNotSame('', $def['word']);
            $this->assertTrue(Icons::has($def['icon']), "$state has a real icon ({$def['icon']})");
            $this->assertNotSame('', $def['tone']);
        }
        $silhouettes = array_map(fn ($s) => Display::statusVocabulary()[$s]['icon'], ['live', 'ending', 'scheduled', 'sold', 'unsold', 'pending', 'cancelled', 'draft']);
        $this->assertSame($silhouettes, array_values(array_unique($silhouettes)), 'each lot state has its own silhouette, so colour is never the only signal');
    }

    public function test_every_icon_has_drawn_markup(): void
    {
        foreach (Icons::names() as $name) {
            $this->assertNotSame('', Icons::markup($name));
        }
        $this->assertSame(Icons::markup('info'), Icons::markup('no-such-icon'), 'an unknown name falls back to a safe icon');
    }
}
