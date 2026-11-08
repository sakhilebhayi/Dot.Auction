<?php

namespace Tests\Support;

use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\AuctionImage;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * The signed-in smoke check (R-42): build a small, complete world, then open every authenticated page as the person
 * who is meant to see it and expect 200. Used two ways:
 *
 *   - Tests\Feature\R42\SignedInSmokeTest runs it in the normal suite (routes as the suite has them);
 *   - tests/Support/signed-in-smoke.sh runs it with `route:cache`, `config:cache` and `event:cache` all active, which
 *     is how production runs (tests/Support/signed-in-smoke.php drives it).
 *
 * pages() lists what is opened; uncoveredRoutes() lists authenticated GET routes that are neither opened nor
 * deliberately skipped, so a new page cannot be forgotten.
 */
final class SignedInSmoke
{
    /** Authenticated GET routes that are not opened, and why. */
    public const SKIPPED = [
        'api/user' => 'token API (Sanctum), not a page; a cookie session answers 401 by design',
        'settlements/{settlement}/payment/callback' => 'asks Paystack to verify a payment; covered by PaymentCallbackTest with a faked gateway',
        'team-invitations/{invitation}' => 'needs a signed invitation link; covered by InviteTeamMemberTest',
        'user/two-factor-qr-code' => 'needs a recent password confirmation and 2FA set up; covered by TwoFactorAuthenticationSettingsTest',
        'user/two-factor-recovery-codes' => 'needs a recent password confirmation and 2FA set up',
        'user/two-factor-secret-key' => 'needs a recent password confirmation and 2FA set up',
        'user/confirm-password' => "Fortify's own page; it redirects when a password confirmation is still fresh, so its status depends on the session",
        'user/confirmed-password-status' => 'JSON status, not a page',
    ];

    /** @var array<string, mixed> */
    private array $world = [];

    /**
     * Create the data. Returns the people, keyed 'seller', 'buyer', 'winner', 'operator'.
     *
     * @return array<string, User>
     */
    public function seed(): array
    {
        $category = AuctionCategory::forceCreate(['name' => 'Smoke', 'slug' => 'smoke-'.uniqid()]);
        $seller = $this->user('Smoke Seller', 'seller');
        $buyer = $this->user('Smoke Buyer', 'buyer');
        $winner = $this->user('Smoke Winner', 'winner');
        $operator = $this->user('Smoke Operator', 'operator', operator: true);

        $live = $this->auction($seller, $category, 'Live lot', ['buy_now_price' => '900.00']);
        Bid::forceCreate(['auction_id' => $live->id, 'bidder_id' => $buyer->id, 'amount' => '220.00', 'is_winning' => true]);
        $live->forceFill(['current_price' => '220.00'])->save();

        $draft = $this->auction($seller, $category, 'Draft lot', ['status' => 'draft']);

        $scheduled = $this->auction($seller, $category, 'Scheduled lot', ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(3)]);

        $pending = $this->auction($seller, $category, 'Reserve lot', ['status' => 'pending_review', 'reserve_price' => '500.00', 'current_price' => '300.00', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subHour()]);
        $pendingBid = Bid::forceCreate(['auction_id' => $pending->id, 'bidder_id' => $buyer->id, 'amount' => '300.00', 'is_winning' => true]);
        ReserveNotMetProposal::forceCreate(['auction_id' => $pending->id, 'bid_id' => $pendingBid->id, 'reserve_price' => '500.00', 'status' => 'pending']);

        $sold = $this->auction($seller, $category, 'Sold lot', ['status' => 'ended', 'current_price' => '400.00', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);
        $soldBid = Bid::forceCreate(['auction_id' => $sold->id, 'bidder_id' => $winner->id, 'amount' => '400.00', 'is_winning' => true]);
        $settlement = AuctionSettlement::unguarded(fn () => AuctionSettlement::create([
            'auction_id' => $sold->id, 'winning_bid_id' => $soldBid->id, 'seller_id' => $seller->id, 'winner_id' => $winner->id,
            'amount_cents' => 40000, 'currency' => 'ZAR', 'status' => 'awaiting_payment', 'payment_due_at' => now()->addDays(2),
        ]));

        Storage::disk(AuctionImage::DISK)->put('smoke/pixel.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
        $image = AuctionImage::create(['auction_id' => $live->id, 'path' => 'smoke/pixel.gif', 'mime' => 'image/gif', 'size_bytes' => 43, 'width' => 1, 'height' => 1, 'position' => 0]);

        $this->world = compact('live', 'draft', 'scheduled', 'pending', 'sold', 'settlement', 'image', 'seller', 'buyer', 'winner', 'operator');

        return compact('seller', 'buyer', 'winner', 'operator');
    }

    /**
     * @return array<int, array{who: string, uri: string}>
     */
    public function pages(): array
    {
        $w = $this->world;
        $live = $w['live']->id;

        return [
            ['who' => 'seller', 'uri' => '/dashboard'],
            ['who' => 'buyer', 'uri' => '/dashboard'],
            ['who' => 'buyer', 'uri' => '/auctions'],
            ['who' => 'buyer', 'uri' => '/auctions?q=live&category='.$w['live']->category_id],
            ['who' => 'buyer', 'uri' => "/auctions/{$live}"],
            ['who' => 'seller', 'uri' => "/auctions/{$live}"],
            ['who' => 'buyer', 'uri' => "/auctions/{$w['scheduled']->id}"],
            ['who' => 'seller', 'uri' => "/auctions/{$w['pending']->id}"],
            ['who' => 'seller', 'uri' => "/auctions/{$w['draft']->id}"],
            ['who' => 'winner', 'uri' => "/auctions/{$w['sold']->id}"],
            ['who' => 'buyer', 'uri' => "/auctions/{$live}/images/{$w['image']->id}"],
            ['who' => 'seller', 'uri' => '/seller/auctions'],
            ['who' => 'seller', 'uri' => '/seller/auctions/create'],
            ['who' => 'seller', 'uri' => "/seller/auctions/{$w['draft']->id}/edit"],
            ['who' => 'seller', 'uri' => "/seller/auctions/{$live}/edit"],
            ['who' => 'winner', 'uri' => '/settlements'],
            ['who' => 'seller', 'uri' => '/settlements'],
            ['who' => 'winner', 'uri' => "/settlements/{$w['settlement']->id}"],
            ['who' => 'seller', 'uri' => "/settlements/{$w['settlement']->id}"],
            ['who' => 'operator', 'uri' => '/operator/settlement-payments'],
            ['who' => 'buyer', 'uri' => '/user/profile'],
            ['who' => 'buyer', 'uri' => '/teams/create'],
            ['who' => 'buyer', 'uri' => '/teams/'.$w['buyer']->personalTeam()->id],
        ];
    }

    /**
     * Authenticated GET routes that pages() neither opens nor SKIPPED explains.
     *
     * @return array<int, string>
     */
    public function uncoveredRoutes(): array
    {
        $opened = collect($this->pages())->map(fn ($p) => $this->pattern($p['uri']))->unique()->all();
        $uncovered = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $needsLogin = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && (str_contains($m, 'Authenticate') || str_starts_with($m, 'auth')) && ! str_contains($m, 'RedirectIfAuthenticated'));

            if (! $needsLogin || array_key_exists($route->uri(), self::SKIPPED)) {
                continue;
            }

            if (! in_array($this->pattern('/'.$route->uri()), $opened, true)) {
                $uncovered[] = $route->uri();
            }
        }

        return array_values(array_unique($uncovered));
    }

    /** "/auctions/12/images/3" -> "auctions/{}/images/{}" so URIs and route patterns compare. */
    private function pattern(string $uri): string
    {
        $path = trim(strtok($uri, '?'), '/');

        return preg_replace(['/\{[^}]+\}/', '/(?<=\/|^)\d+(?=\/|$)/', '/(?<=\/|^)[0-9a-f]{8}-[0-9a-f-]{27}(?=\/|$)/'], '{}', $path);
    }

    private function user(string $name, string $key, bool $operator = false): User
    {
        $user = new User(['name' => $name, 'email' => "smoke-{$key}-".uniqid().'@example.test', 'password' => Hash::make('password')]);
        $user->forceFill(['email_verified_at' => now(), 'is_platform_operator' => $operator])->save();

        $team = Team::forceCreate(['user_id' => $user->id, 'name' => "{$name}'s Team", 'personal_team' => true]);
        $user->ownedTeams()->save($team);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function auction(User $seller, AuctionCategory $category, string $title, array $overrides = []): Auction
    {
        return Auction::forceCreate(array_merge([
            'seller_id' => $seller->id, 'category_id' => $category->id, 'title' => $title, 'description' => 'Smoke test lot.',
            'starting_price' => '200.00', 'current_price' => '200.00', 'bid_increment' => '20.00', 'status' => 'active',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(),
        ], $overrides));
    }
}
