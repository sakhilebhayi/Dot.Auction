{{--
  The public page. Plain facts about how a sale on Dot.Auction works, taken from the product's own settings (config/auction.php) and its terms.
  No live figures, no activity and no counts: the one picture is a labelled example of the bid ladder.
--}}
@php
    use App\Models\Auction;
    $window = config('auction.snipe_window_minutes');
    $extension = config('auction.snipe_extension_minutes');
    $maxExtensions = config('auction.snipe_max_extensions');

    // An unsaved lot, only to draw the ladder. Nothing is stored and nothing here is real.
    $example = (new Auction)->forceFill([
        'title' => 'Example lot', 'status' => 'active',
        'starting_price' => '1000.00', 'current_price' => '1250.00', 'bid_increment' => '50.00',
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3), 'snipe_extensions' => 0,
    ]);
    $example->setRelation('winningBid', null);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['title' => null, 'description' => 'Dot.Auction lists every lot with its price, bid step and closing time, and says plainly where each sale stands.'])
</head>
<body class="wl-body">
    <a class="skip-link" href="#main">Skip to main content</a>

    <header class="wl-top">
        <x-ds.brand :href="url('/')" :width="108" />
        <nav class="wl-top__nav" aria-label="Page sections">
            <a href="#how">How a sale works</a>
            <a href="#trust">What you can rely on</a>
        </nav>
        <div class="wl-top__end">
            <x-ds.theme-toggle />
            @auth
                <x-ds.button :href="route('dashboard')" size="sm">Open dashboard</x-ds.button>
            @else
                <x-ds.button :href="route('login')" size="sm">Sign in</x-ds.button>
            @endauth
        </div>
    </header>

    <main id="main" tabindex="-1">
        <section class="wl-hero" aria-labelledby="wl-title">
            <div class="wl-hero__text">
                <h1 id="wl-title" class="wl-hero__title">Bid on lots until the hammer falls.</h1>
                <p class="wl-hero__lede">Every lot shows its price, its bid step and its closing time.</p>
                <div class="wl-hero__actions">
                    @auth
                        <x-ds.button :href="route('auctions.index')" size="lg" icon-right="arrow-right">Browse lots</x-ds.button>
                    @else
                        <x-ds.button :href="route('login')" size="lg" icon-right="arrow-right">Sign in to bid</x-ds.button>
                    @endauth
                    <x-ds.button href="#how" variant="quiet" size="lg">How a sale works</x-ds.button>
                </div>
            </div>

            <figure class="wl-hero__figure" aria-labelledby="wl-fig">
                <figcaption id="wl-fig" class="wl-hero__cap">An example of a bid ladder. Real lots show the same steps with their own numbers.</figcaption>
                <x-ds.bid-ladder :auction="$example" :interactive="false" :steps="3" />
                <dl class="wl-hero__facts">
                    <div><dt>Bid step</dt><dd><x-ds.money amount="50" size="md" /></dd></div>
                    <div><dt>Next valid bid</dt><dd><x-ds.money amount="1300" size="md" /></dd></div>
                </dl>
            </figure>
        </section>

        <section class="wl-section" id="how" aria-labelledby="wl-how">
            <div class="wl-section__head">
                <h2 id="wl-how">How a sale works</h2>
                <p>One lot, from listing to payment.</p>
            </div>
            <ol class="wl-steps">
                <li>
                    <h3>A seller lists the lot</h3>
                    <p>The seller sets a start price and a bid step, and may add a private reserve and a buy now price. They choose when the lot opens and when it closes.</p>
                </li>
                <li>
                    <h3>Buyers bid</h3>
                    <p>A bid must be at least the current price plus the bid step. A bid is a promise to buy if you are the highest bidder when the lot closes, and it cannot be withdrawn.</p>
                </li>
                <li>
                    <h3>The lot closes</h3>
                    <p>It closes at its set time, shown in South African time. A bid in the last {{ $window }} minutes moves the close out by {{ $extension }} minutes, up to {{ $maxExtensions }} times, so nobody wins on the last second.</p>
                </li>
                <li>
                    <h3>The result is stated</h3>
                    <p>The highest bid wins when it meets the reserve. When it falls short, the lot waits and the seller decides whether to accept the top bid. The lot page says which of these happened.</p>
                </li>
                <li>
                    <h3>The winner pays</h3>
                    <p>The winner pays on the sale page. Card payment through Paystack opens when Dot.Auction switches payments on, and payouts to sellers are arranged by hand for now.</p>
                </li>
            </ol>
        </section>

        <section class="wl-section wl-section--tint" id="trust" aria-labelledby="wl-trust">
            <div class="wl-section__head">
                <h2 id="wl-trust">What you can rely on</h2>
            </div>
            <dl class="wl-rely">
                <div><dt>A private reserve</dt><dd>Bidders only ever see whether the reserve has been met. The amount stays with the seller.</dd></div>
                <div><dt>One price at a time</dt><dd>Each bid is checked against the latest price when it is recorded, so two bidders cannot take the same price.</dd></div>
                <div><dt>Notices that mean something</dt><dd>You are told when you are outbid, when a lot you watch is closing soon, and when you win or sell.</dd></div>
                <div><dt>Plain money</dt><dd>Prices are in {{ config('auction.currency_code') }} and every sale page says what is owed and what is not available yet.</dd></div>
            </dl>
        </section>

        <section class="wl-close" aria-labelledby="wl-close">
            <h2 id="wl-close">Have a Dot account? You can bid today.</h2>
            <p>Dot.Auction accounts come from InfoDot. Sign in from InfoDot, or use your Dot.Auction sign-in if you already have one.</p>
            @guest
                <x-ds.button :href="route('login')" size="lg" icon-right="arrow-right">Sign in</x-ds.button>
            @else
                <x-ds.button :href="route('auctions.index')" size="lg" icon-right="arrow-right">Browse lots</x-ds.button>
            @endguest
        </section>
    </main>

    <footer class="gx-foot">
        <nav aria-label="Legal">
            <a href="{{ route('terms.show') }}">Terms</a>
            <a href="{{ route('policy.show') }}">Privacy</a>
            <a href="{{ route('cookies') }}">Cookies</a>
        </nav>
        <p>Dot.Auction is part of the Dot ecosystem.</p>
    </footer>
    <x-ds.toasts />
</body>
</html>
