<?php

namespace Tests\Feature\R42;

use App\Livewire\NotificationBell;
use App\Models\Watchlist;
use App\Notifications\OutbidNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSettlements;
use Tests\Feature\Discover\BuildsDiscoverWorld;
use Tests\TestCase;

/**
 * Design jury 1, P0 2: a lot called "Brass ship's lantern" showed `ship&#039;s` in the lot page's h1 (the layout re-escaped what a header
 * slot had already escaped). A title is escaped exactly once, wherever it is printed, and nothing in it is ever treated as markup.
 */
class TitleEscapingTest extends TestCase
{
    use BuildsDiscoverWorld;
    use BuildsSettlements;
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function titles(): array
    {
        return [
            'apostrophe' => ["Brass ship's lantern"],
            'double quote' => ['The "Big" Bell'],
            'ampersand' => ['Salt & pepper set'],
            'already an entity' => ['Fish &amp; chips sign'],
            'markup' => ['<b>Bold</b> lamp'],
            'script' => ['<script>alert(1)</script> clock'],
            'unicode' => ['Café Zoë, ünïcode 咖啡 lamp'],
            'everything' => ['Tom\'s "rare" <i>kettle</i> & more'],
        ];
    }

    /** Visible text of <main> plus the rendered HTML, so we can check both what a person reads and what the browser is given. */
    private function inspect(string $html, string $title, string $where): void
    {
        preg_match('~<main\b.*?</main>~s', $html, $m);
        $main = $m[0] ?? $html;

        // A title that itself contains an entity ("Fish &amp; chips") is printed as the characters typed, so its own ampersand is escaped once:
        // `&amp;amp;` is right for it and wrong for every other title.
        if (! str_contains($title, '&amp;')) {
            $this->assertStringNotContainsString('&amp;#', $main, "$where: a numeric entity was escaped twice");
            $this->assertStringNotContainsString('&amp;amp;', $main, "$where: an ampersand was escaped twice");
            $this->assertStringNotContainsString('&amp;quot;', $main, "$where: a quote was escaped twice");
            $this->assertStringNotContainsString('&amp;lt;', $main, "$where: a bracket was escaped twice");
        }
        $this->assertStringNotContainsString('<b>Bold</b>', $html, "$where: markup in a title must not become markup");
        $this->assertStringNotContainsString('<script>alert(1)', $html, "$where: a script in a title must not run");
        $this->assertStringNotContainsString('<i>kettle</i>', $html, "$where: markup in a title must not become markup");

        $text = html_entity_decode(strip_tags(preg_replace('~<(script|style)\b.*?</\1>~s', '', $main)));
        $this->assertStringContainsString($title, preg_replace('/\s+/', ' ', $text), "$where: the title reads as written");
    }

    #[DataProvider('titles')]
    public function test_the_lot_page_prints_the_title_once_escaped_in_h1_title_and_panel(string $title): void
    {
        $viewer = $this->person('Viewer');
        $lot = $this->lot(['title' => $title]);
        $html = $this->actingAs($viewer)->get(route('auctions.show', $lot))->assertOk()->getContent();

        $this->inspect($html, $title, 'lot page');

        // The h1: the text a person reads, decoded once, equals the title.
        $this->assertMatchesRegularExpression('~<h1[^>]*>(.*?)</h1>~s', $html);
        preg_match('~<h1[^>]*>(.*?)</h1>~s', $html, $h1);
        $this->assertSame($title, html_entity_decode(trim($h1[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'h1 decodes once to the title');

        // The tab title.
        preg_match('~<title>(.*?)</title>~s', $html, $tab);
        $this->assertSame($title.' | '.config('app.name', 'Dot.Auction'), html_entity_decode($tab[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'tab title decodes once to the title');
        if (! str_contains($title, '&amp;')) {
            $this->assertStringNotContainsString('&amp;#', $tab[1]);
            $this->assertStringNotContainsString('&amp;amp;', $tab[1]);
        }

        // The meta description, an attribute.
        preg_match('~<meta name="description" content="(.*?)">~s', $html, $desc);
        $this->assertStringNotContainsString('&amp;#', $desc[1] ?? '');
    }

    #[DataProvider('titles')]
    public function test_every_list_that_names_a_lot_prints_the_title_once_escaped(string $title): void
    {
        $seller = $this->person('Seller');
        $buyer = $this->person('Buyer');
        $rival = $this->person('Rival');
        $this->enablePaystack();

        // A live lot the buyer bid on and watches, and was outbid on.
        $live = $this->lot(['title' => $title], $seller);
        $this->bid($live, $buyer, '120.00');
        $this->bid($live, $rival, '140.00');
        Watchlist::query()->create(['user_id' => $buyer->id, 'auction_id' => $live->id]);
        $buyer->notify(new OutbidNotification($live->bids()->latest('id')->first()));

        // A lot the buyer won, with a sale to pay.
        $won = $this->endedLot($buyer, '300.00', ['title' => $title, 'seller_id' => $seller->id]);
        $sale = $this->sale($won, $buyer);

        // A lot closing soon, so Home lists it too.
        $this->lot(['title' => $title, 'ends_at' => now()->addHours(3)], $seller);

        $pages = [
            'browse' => [$buyer, route('auctions.index')],
            'home' => [$buyer, route('dashboard')],
            'my bids' => [$buyer, route('buyer.bids')],
            'watching' => [$buyer, route('buyer.watchlist')],
            'won' => [$buyer, route('buyer.won')],
            'payments' => [$buyer, route('settlements.index')],
            'sale' => [$buyer, route('settlements.show', $sale)],
            'notifications' => [$buyer, route('notifications.index')],
            'my lots' => [$seller, route('seller.auctions.index')],
            'seller home' => [$seller, route('dashboard')],
        ];

        foreach ($pages as $where => [$user, $url]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->inspect($html, $title, $where);
        }
    }

    public function test_the_bell_panel_prints_a_title_in_a_notice_once_escaped(): void
    {
        $title = 'Tom\'s "rare" <i>kettle</i> & more';
        $seller = $this->person('Seller');
        $buyer = $this->person('Buyer');
        $rival = $this->person('Rival');
        $lot = $this->lot(['title' => $title], $seller);
        $this->bid($lot, $buyer, '120.00');
        $this->bid($lot, $rival, '140.00');
        $buyer->notify(new OutbidNotification($lot->bids()->latest('id')->first()));

        $html = Livewire::actingAs($buyer)->test(NotificationBell::class)->call('toggle')->html();

        $this->assertStringNotContainsString('<i>kettle</i>', $html);
        $this->assertStringNotContainsString('&amp;#', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringContainsString('Tom&#039;s &quot;rare&quot; &lt;i&gt;kettle&lt;/i&gt; &amp; more', $html);
    }
}
