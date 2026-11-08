<?php

namespace Database\Seeders;

use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\AuctionItem;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\SettlementEvent;
use App\Models\SettlementPayment;
use App\Models\Team;
use App\Models\User;
use App\Models\Watchlist;
use App\Notifications\AuctionEndingSoonNotification;
use App\Notifications\SettlementOverdueNotification;
use App\Notifications\SettlementPaidNotification;
use App\Services\Auctions\AuctionImageManager;
use App\Services\Auctions\AuctionSettler;
use App\Services\Auctions\BidPlacer;
use App\Services\Auctions\BuyNowPurchaser;
use App\Services\Auctions\ListingManager;
use App\Services\Auctions\SettlementLifecycle;
use App\Services\Paystack\PaystackConfig;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A believable, fictional South African demo world for local design and QA work.
 *
 * Everything goes through the real services (ListingManager, BidPlacer, BuyNowPurchaser, AuctionSettler,
 * SettlementLifecycle, AuctionImageManager) so the data has the shape production data has. Only
 * timestamps are moved afterwards, to give lots a past ("opened two days ago") the services cannot
 * create in the present. People, lots and photos are invented; photos are plainly labelled
 * placeholders.
 *
 * Two worlds, both coherent. By default online payment is off (no Paystack key), so only the states that can exist
 * with payment off are written: sales awaiting payment, with no deadline, no payment attempts and no overdue or
 * cancelled notices. With `$payments` the process runs with a FAKE test key (`sk_test_demo...`, never sent anywhere:
 * the seeder calls no gateway), the deadline and sale rules apply as they do live, and the full set is written:
 * a started checkout, a failed attempt, paid, overdue and cancelled sales, each with the notices and history that
 * state really produces.
 *
 * Refuses to run in production. Idempotent: if the demo world is already complete it changes
 * nothing; a half-finished earlier run is removed and rebuilt. `$fresh` rebuilds on demand (the
 * lots are timed relative to "now", so a stale world is best rebuilt).
 *
 * Every demo account has the password in PASSWORD. That is acceptable only because this seeder
 * cannot run in production.
 */
class AuctionDemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public const DOMAIN = 'demo.auction.test';

    /** The last account created; its presence means a run finished. */
    public const COMPLETION_MARKER = 'zanele.mthembu@'.self::DOMAIN;

    /** @var array<string, array{name: string, city: string}> */
    private const PEOPLE = [
        'thandi' => ['name' => 'Thandi Mokoena', 'city' => 'Cape Town'],
        'pieter' => ['name' => 'Pieter van der Merwe', 'city' => 'Stellenbosch'],
        'lerato' => ['name' => 'Lerato Dlamini', 'city' => 'Johannesburg'],
        'sipho' => ['name' => 'Sipho Nkosi', 'city' => 'Durban'],
        'aisha' => ['name' => 'Aisha Patel', 'city' => 'Pretoria'],
        'johan' => ['name' => 'Johan Botha', 'city' => 'Bloemfontein'],
        'naledi' => ['name' => 'Naledi Khumalo', 'city' => 'Johannesburg'],
        'zanele' => ['name' => 'Zanele Mthembu', 'city' => 'Gqeberha'],
    ];

    /** @var array<string, string> slug => name */
    private const CATEGORIES = [
        'electronics' => 'Electronics',
        'home-furniture' => 'Home & Furniture',
        'art-collectables' => 'Art & Collectables',
        'vehicles-parts' => 'Vehicles & Parts',
        'outdoors-sport' => 'Outdoors & Sport',
        'jewellery-watches' => 'Jewellery & Watches',
        'books-music' => 'Books & Music',
        'tools-equipment' => 'Tools & Equipment',
    ];

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, AuctionCategory> */
    private array $categories = [];

    private int $photoSeed = 0;

    private int $draftCount = 0;

    private int $lotCount = 0;

    /** The fake test key a `--payments` run uses. Obviously not a real key; only its prefix matters to PaystackConfig. */
    public const FAKE_PAYSTACK_KEY = 'sk_test_demo_fake_gateway_never_sent';

    public function __construct(private readonly bool $fresh = false, private readonly bool $payments = false) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder never runs in production.');
        }

        $complete = User::query()->where('email', self::COMPLETION_MARKER)->exists();

        if ($complete && ! $this->fresh) {
            $this->say('The demo world already exists; nothing changed. Use --fresh to rebuild it.');

            return;
        }

        // The services queue listeners (settlement hand-off). Run them inline so the world is complete when this returns.
        // Nothing should be pushed to a WebSocket server while seeding.
        config(['queue.default' => 'sync', 'broadcasting.default' => 'null']);

        // The payment switch for this run only. Put back afterwards so a test (or a shell that goes on to serve) keeps its own setting.
        $before = config('paystack.secret_key');
        $this->payments && config(['paystack.secret_key' => self::FAKE_PAYSTACK_KEY, 'paystack.base_url' => 'https://api.paystack.co']);

        try {
            $this->purge();
            $this->createPeople();
            $this->createCategories();
            $this->createLots();
            $this->createWatchlists();
            $this->spreadNotificationTimes();
            $this->createExtraNotifications();
            $this->createOperatorEvidence();
            $this->createZanele();
        } finally {
            config(['paystack.secret_key' => $before]);
        }

        $this->say('Demo world ready'.($this->payments ? ' (with payments: started, failed, paid, overdue and cancelled sales)' : ' (payment off: sales are awaiting payment)').'. Sign in as any demo user with the password "'.self::PASSWORD.'" or use /dev/login/{email}.');
        $this->payments && $this->say('To see Pay now on these sales, serve the app with PAYSTACK_SECRET_KEY='.self::FAKE_PAYSTACK_KEY.' (pressing Pay now then fails at Paystack: the gateway-error state).');
    }

    // ───────────────────────────── people ─────────────────────────────

    private function email(string $key): string
    {
        return Str::of(self::PEOPLE[$key]['name'])->lower()->replace(['van der ', ' '], ['', '.'])->append('@'.self::DOMAIN)->toString();
    }

    /** @return array<int, string> */
    private function demoEmails(): array
    {
        return array_map(fn (string $key) => $this->email($key), array_keys(self::PEOPLE));
    }

    private function purge(): void
    {
        $users = User::query()->whereIn('email', $this->demoEmails())->get();

        if ($users->isEmpty()) {
            return;
        }

        $ids = $users->pluck('id')->all();

        DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $ids)->delete();
        DB::table('settlement_payments')->whereIn('initiated_by', $ids)->delete();
        DB::table('settlement_payments')->where('reference', 'like', 'AUC-DEMO-%')->delete();

        // Models, not a bulk delete, so the auction and image model events remove the photo files.
        Auction::query()->whereIn('seller_id', $ids)->get()->each->delete();
        DB::table('watchlists')->whereIn('user_id', $ids)->delete();

        foreach ($users as $user) {
            DB::table('team_user')->where('user_id', $user->id)->delete();
            Team::query()->where('user_id', $user->id)->get()->each->delete();
            $user->delete();
        }
    }

    private function createPeople(): void
    {
        foreach (self::PEOPLE as $key => $person) {
            if ($key === 'zanele') {
                continue; // created last: the completion marker
            }
            $this->users[$key] = $this->makeUser($key);
        }

        // Platform operator: the flag is guarded, so write it the way the artisan command does.
        $this->users['naledi']->forceFill(['is_platform_operator' => true])->save();
    }

    private function makeUser(string $key): User
    {
        $name = self::PEOPLE[$key]['name'];

        $user = User::forceCreate([
            'name' => $name,
            'email' => $this->email($key),
            'email_verified_at' => now(),
            'password' => self::PASSWORD,
        ]);

        $team = Team::forceCreate([
            'user_id' => $user->id,
            'name' => Str::before($name, ' ')."'s Team",
            'personal_team' => true,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user;
    }

    private function createZanele(): void
    {
        $this->users['zanele'] = $this->makeUser('zanele');
    }

    private function createCategories(): void
    {
        foreach (self::CATEGORIES as $slug => $name) {
            $this->categories[$slug] = AuctionCategory::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }

    // ───────────────────────────── lots ─────────────────────────────

    private function createLots(): void
    {
        $t = $this->users['thandi'];
        $p = $this->users['pieter'];
        $lerato = $this->users['lerato'];
        $sipho = $this->users['sipho'];
        $aisha = $this->users['aisha'];
        $johan = $this->users['johan'];

        // ── Thandi (the main seller) ──

        $this->lot($t, 'Vintage Kodak Brownie camera', 'art-collectables', 150, 25, [
            'description' => 'Box camera in working order, leather strap, original canvas case. Lens clean, shutter fires, no film included.',
            'photos' => 2, 'status' => 'draft', 'items' => [['Kodak Brownie camera', 'used', 'Cape Town']],
        ]);

        $this->lot($t, 'Oak dining table, seats eight', 'home-furniture', 2500, 100, [
            'description' => 'Solid oak, 2.1 m long. Light surface marks, no wobble. Collection from Claremont only.',
            'photos' => 0, 'status' => 'draft',
        ]);

        $this->lot($t, 'Samsung 55-inch QLED television', 'electronics', 3500, 150, [
            'description' => 'Bought two years ago, boxed with remote and stand. Panel has no marks or dead pixels.',
            'photos' => 3, 'reserve' => 4500, 'buyNow' => 7000,
            'opens' => now()->addDay()->setTime(9, 0), 'closes' => now()->addDays(4)->setTime(20, 0),
            'items' => [['Samsung QLED TV with stand and remote', 'good', 'Cape Town']],
        ]);

        $this->lot($t, 'Yamaha F310 acoustic guitar', 'books-music', 800, 50, [
            'description' => 'Folk-size acoustic, new strings fitted last month. Small scratch on the lower bout. Gig bag included.',
            'photos' => 3, 'reserve' => 1200, 'openedAgo' => '2 days', 'closes' => now()->addHours(30),
            'items' => [['Yamaha F310', 'good', 'Cape Town'], ['Padded gig bag', 'good', 'Cape Town']],
            'bids' => [[$lerato, 850, 2600], [$sipho, 900, 2100], [$lerato, 1000, 1500], [$aisha, 1100, 900], [$sipho, 1250, 200]],
        ]);

        $this->lot($t, 'Mountain bike, 29-inch aluminium frame', 'outdoors-sport', 2800, 100, [
            'description' => 'Hardtail with front suspension, 21 speeds. New tyres. Brakes need a service.',
            'photos' => 2, 'reserve' => 4500, 'openedAgo' => '1 day', 'closes' => now()->addDays(2),
            'items' => [['Mountain bike', 'used', 'Cape Town']],
            'bids' => [[$aisha, 2900, 1300], [$johan, 3100, 700]],
        ]);

        $braai = $this->lot($t, 'Stainless steel gas braai, two burner', 'outdoors-sport', 900, 50, [
            'description' => 'Portable two-burner gas braai with grill and plate. Gas bottle not included.',
            'photos' => 2, 'openedAgo' => '3 days', 'closes' => now()->addMinutes(40),
            'bids' => [[$lerato, 950, 4000], [$johan, 1000, 2500], [$lerato, 1100, 600]],
        ]);

        $this->lot($t, 'Wool rug, 2 m by 3 m', 'home-furniture', 1200, 50, [
            'description' => 'Hand-tufted wool rug in grey and cream. Smoke-free home. Rolled for collection.',
            'photos' => 2, 'openedAgo' => '5 days', 'closes' => now()->addMinutes(3),
            'bids' => [[$sipho, 1250, 30, 3], [$aisha, 1300, 2, 2]], // staged late bids: the real service extends the close twice
        ]);

        $laptop = $this->lot($t, 'Dell Latitude laptop, i5, 16 GB', 'electronics', 3000, 100, [
            'description' => 'Business laptop, 256 GB SSD, battery holds about four hours. Fresh Windows install, charger included.',
            'photos' => 2, 'reserve' => 3500, 'openedAgo' => '5 days', 'closedAgo' => '9 hours',
            'items' => [['Dell Latitude 5420', 'good', 'Cape Town']],
            'bids' => [[$lerato, 3100, 6000], [$sipho, 3300, 5000], [$lerato, 3500, 4000], [$sipho, 3700, 3000]],
        ]);

        $chairs = $this->lot($t, 'Six leather dining chairs', 'home-furniture', 1800, 100, [
            'description' => 'Set of six, brown leather, stained oak legs. One has a small tear on the seat edge (photographed).',
            'photos' => 2, 'openedAgo' => '8 days', 'closedAgo' => '4 days',
            'bids' => [[$sipho, 1900, 9000], [$aisha, 2000, 8000], [$sipho, 2200, 6000]],
        ]);

        $mixer = $this->lot($t, 'KitchenAid stand mixer, 4.8 L', 'home-furniture', 2500, 100, [
            'description' => 'Tilt-head mixer in red, with whisk, hook and paddle. Used weekly, works perfectly.',
            'photos' => 2, 'reserve' => 4000, 'openedAgo' => '6 days', 'closedAgo' => '3 hours',
            'bids' => [[$lerato, 2600, 7000], [$aisha, 2800, 5000], [$lerato, 3000, 1000]],
        ]);

        $this->lot($t, 'Garden tool set', 'tools-equipment', 300, 20, [
            'description' => 'Spade, fork, rake and trowel. Withdrawn: the seller decided to keep them.',
            'photos' => 1, 'openedAgo' => '2 days', 'status' => 'cancelled',
        ]);

        $this->lot($t, 'Bosch cordless drill kit', 'tools-equipment', 500, 25, [
            'description' => 'Two batteries, charger, case and a set of bits. Hammer function works.',
            'photos' => 2, 'buyNow' => 1500, 'openedAgo' => '1 day', 'closes' => now()->addDays(3),
            'bids' => [[$johan, 550, 500], [$lerato, 600, 100]],
        ]);

        $this->lot($t, 'Box of assorted hardcover novels', 'books-music', 100, 10, [
            'description' => 'About forty hardcovers, mixed fiction. Some shelf wear.',
            'photos' => 0, 'openedAgo' => '9 days', 'closedAgo' => '2 days',
        ]);

        // ── Pieter (second seller) ──

        $this->lot($p, "Brass ship's lantern", 'art-collectables', 400, 25, [
            'description' => 'Heavy brass lantern with glass chimney, converted to take a tea light. Some verdigris on the base.',
            'photos' => 3, 'reserve' => 700, 'openedAgo' => '1 day', 'closes' => now()->addDays(1)->addHours(6),
            'bids' => [[$sipho, 425, 1000], [$aisha, 475, 600], [$johan, 550, 300], [$sipho, 600, 90]],
        ]);

        $this->lot($p, 'Set of four alloy wheels, 16-inch', 'vehicles-parts', 2000, 100, [
            'description' => 'Fits most hatchbacks with a 4x100 pattern. Kerb marks on two wheels, tyres worn.',
            'photos' => 2, 'buyNow' => 3800, 'openedAgo' => '6 hours', 'closes' => now()->addDays(5),
        ]);

        $this->lot($p, 'Land Rover Defender parts lot', 'vehicles-parts', 1500, 100, [
            'description' => 'Two doors, a bonnet and a rear tailgate from a 1998 Defender 110. Collection only.',
            'photos' => 2, 'opens' => now()->addHours(10), 'closes' => now()->addDays(6),
        ]);

        $potjie = $this->lot($p, 'Cast iron potjie, size 3', 'outdoors-sport', 250, 20, [
            'description' => 'Seasoned three-legged pot with lid. No cracks.',
            'photos' => 1, 'openedAgo' => '10 days', 'closedAgo' => '6 days',
            'bids' => [[$aisha, 280, 12000], [$johan, 300, 11000], [$aisha, 340, 9000]],
        ]);

        $mower = $this->lot($p, 'Petrol lawnmower, 150 cc', 'tools-equipment', 700, 50, [
            'description' => 'Self-propelled, rear bag. Starts first pull. Blade recently sharpened.',
            'photos' => 2, 'openedAgo' => '9 days', 'closedAgo' => '5 days',
            'bids' => [[$lerato, 750, 11000], [$johan, 800, 9000], [$lerato, 900, 7000]],
        ]);

        $rod = $this->lot($p, 'Fishing rod and reel combo', 'outdoors-sport', 350, 25, [
            'description' => 'Two-piece 2.7 m rod with a matched spinning reel. Line is old; spool is full.',
            'photos' => 1, 'openedAgo' => '12 days', 'closedAgo' => '8 days',
            'bids' => [[$sipho, 400, 15000], [$aisha, 450, 14000], [$sipho, 500, 12000]],
        ]);

        $painting = $this->lot($p, 'Karoo landscape, oil on canvas', 'art-collectables', 3000, 250, [
            'description' => 'Original oil, 60 by 90 cm, framed. Signed lower right. The painter is a private local artist.',
            'photos' => 2, 'reserve' => 6500, 'openedAgo' => '7 days', 'closedAgo' => '2 hours',
            'bids' => [[$lerato, 3250, 8000], [$aisha, 3750, 6000], [$sipho, 4250, 3000]],
        ]);

        $this->lot($p, 'Vinyl record collection, 40 records', 'books-music', 600, 50, [
            'description' => 'Mixed South African jazz and pop pressings. Sleeves vary from fair to good.',
            'photos' => 1, 'status' => 'draft',
        ]);

        $this->lot($p, 'Four-person camping tent', 'outdoors-sport', 450, 25, [
            'description' => 'Dome tent with fly sheet. Withdrawn after the seller found a missing pole.',
            'photos' => 1, 'openedAgo' => '3 days', 'status' => 'cancelled',
        ]);

        $watch = $this->lot($p, 'Gold-plated pocket watch', 'jewellery-watches', 500, 50, [
            'description' => 'Wind-up movement, keeps time to within a few minutes a day. Plating worn at the edges.',
            'photos' => 2, 'reserve' => 1500, 'openedAgo' => '10 days', 'closedAgo' => '3 days',
            'bids' => [[$sipho, 550, 13000], [$johan, 650, 11000]],
        ]);

        $this->lot($p, 'Rocking chair, solid wood', 'home-furniture', 600, 50, [
            'description' => 'Hand-finished, sturdy, no cushion. Seat is a little worn.',
            'photos' => 2, 'openedAgo' => '3 days', 'closes' => now()->addDays(2)->addHours(3),
            'bids' => [[$aisha, 650, 3000], [$lerato, 700, 1500]],
        ]);

        $camera = $this->lot($p, 'Nikon D3500 camera body', 'electronics', 3000, 100, [
            'description' => 'Body only with battery and charger. Shutter count about 9,000.',
            'photos' => 2, 'buyNow' => 5200, 'openedAgo' => '2 days', 'closes' => now()->addDays(2),
            'bids' => [[$lerato, 3100, 2200]],
        ]);

        // ── Endings that need the settler or buy-now ──

        $this->endIfDue($laptop);
        $this->endIfDue($chairs);
        $this->endIfDue($mixer);
        $this->endIfDue($potjie);
        $this->endIfDue($mower);
        $this->endIfDue($rod);
        $this->endIfDue($painting);
        $this->endIfDue($watch);
        $this->endIfDue(Auction::query()->where('title', 'Box of assorted hardcover novels')->firstOrFail());

        // Buy now: Johan takes the Nikon the way the page does it (a live lot with a lower bid on it).
        app(BuyNowPurchaser::class)->buy($camera, $johan);
        $this->backdate($camera->fresh(), now()->subHours(20));

        // ── Settlement states ──

        $this->settlementStates($laptop, $chairs, $potjie, $mower, $rod, $camera);

        // ── Reserve decisions ──

        // Kitchen mixer: left pending on Thandi's dashboard. Painting: left pending on Pieter's.
        // Watch: Pieter rejected it (ended unsold).
        $this->rejectProposal($watch);

        // ── Notifications that arise from real activity are already there; add the ending-soon notice ──
        $this->users['lerato']->notify(new AuctionEndingSoonNotification($braai));
        $this->users['johan']->notify(new AuctionEndingSoonNotification($braai));

    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function lot(User $seller, string $title, string $category, int $starting, int $increment, array $spec): Auction
    {
        $status = $spec['status'] ?? 'active';
        $opens = $spec['opens'] ?? null;
        $closes = $spec['closes'] ?? null;

        // Creation rules (an hour minimum, a closing time in the future) are satisfied with a placeholder window;
        // the real window is applied afterwards.
        $createdCloses = $opens instanceof CarbonInterface ? $closes : now()->addDays(3);

        $auction = app(ListingManager::class)->create($seller, [
            'title' => $title,
            'description' => $spec['description'] ?? null,
            'category_id' => $this->categories[$category]->id,
            'starting_price' => Auction::fromCents($starting * 100),
            'bid_increment' => Auction::fromCents($increment * 100),
            'reserve_price' => isset($spec['reserve']) ? Auction::fromCents($spec['reserve'] * 100) : null,
            'buy_now_price' => isset($spec['buyNow']) ? Auction::fromCents($spec['buyNow'] * 100) : null,
            'starts_at' => $opens,
            'ends_at' => $createdCloses ?? now()->addDays(3),
        ]);

        foreach ($spec['items'] ?? [] as [$name, $condition, $location]) {
            AuctionItem::create(['auction_id' => $auction->id, 'name' => $name, 'condition' => $condition, 'location' => $location]);
        }

        $this->photos($auction, (int) ($spec['photos'] ?? 0), $category);

        if ($status === 'draft') {
            // Drafts were started at different times: some yesterday, some last week.
            $this->backdate($auction, now()->subHours(9 + (++$this->draftCount * 37) % 150));

            return $auction->fresh();
        }

        $auction = app(ListingManager::class)->publish($auction);

        if ($opens instanceof CarbonInterface) {
            return $auction->fresh(); // scheduled
        }

        // Live window: opened in the past, closing as specified. Set before any bid so the anti-snipe rule
        // judges the real close. A lot that has to end in the past stays open (far close) until the settler runs.
        $openedAgo = $this->ago($spec['openedAgo'] ?? '1 day');
        $auction->forceFill(['starts_at' => $openedAgo, 'ends_at' => $closes ?? now()->addDays(2)])->save();
        // Listed a few hours before it opened, by a different amount each time.
        $this->backdate($auction, $openedAgo->copy()->subMinutes(70 + (++$this->lotCount * 53) % 400));

        foreach ($spec['bids'] ?? [] as $entry) {
            [$bidder, $amount, $minutesAgo] = $entry;

            // A fourth value moves the close to that many minutes away first: it stages a late bid.
            if (isset($entry[3])) {
                $auction->forceFill(['ends_at' => now()->addMinutes($entry[3])])->save();
            }

            $bid = app(BidPlacer::class)->place($auction->fresh(), $bidder, $amount);
            $this->backdateBid($bid, $minutesAgo);
        }

        $auction = $auction->fresh();

        if (isset($spec['closedAgo'])) {
            $this->closedAgo[$auction->id] = $this->ago($spec['closedAgo']);
        }

        if ($status === 'cancelled') {
            $cancelled = app(ListingManager::class)->cancel($auction);
            // Withdrawn some hours after it opened, not at the moment of seeding.
            DB::table('auctions')->where('id', $cancelled->id)->update(['updated_at' => $openedAgo->copy()->addHours(6 + ($this->lotCount * 11) % 19)]);

            return $cancelled->fresh();
        }

        return $auction;
    }

    /** @var array<int, CarbonInterface> */
    private array $closedAgo = [];

    /** End a lot whose closing time was asked to be in the past, using the real settler. */
    private function endIfDue(Auction $auction): void
    {
        $auction = $auction->fresh();
        $at = $this->closedAgo[$auction->id] ?? now()->subMinute();

        $auction->forceFill(['ends_at' => $at])->save();

        app(AuctionSettler::class)->settle($auction->id);

        $auction = $auction->fresh();
        $this->backdateAfterSettle($auction, $at);
    }

    private function backdateAfterSettle(Auction $auction, CarbonInterface $at): void
    {
        $settlement = AuctionSettlement::query()->where('auction_id', $auction->id)->first();

        if ($settlement !== null) {
            $settlement->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }

        DB::table('reserve_not_met_proposals')->where('auction_id', $auction->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    private function ago(string $spec): CarbonInterface
    {
        [$n, $unit] = explode(' ', $spec);

        return now()->sub(Str::plural($unit, 2), (int) $n);
    }

    private function backdate(Auction $auction, CarbonInterface $at): void
    {
        DB::table('auctions')->where('id', $auction->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    private function unlockBidHistory(callable $edit): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('ALTER TABLE bids DISABLE TRIGGER bids_append_only_update');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS bids_append_only_update');
        }

        try {
            $edit();
        } finally {
            if ($driver === 'pgsql') {
                DB::unprepared('ALTER TABLE bids ENABLE TRIGGER bids_append_only_update');
            } elseif ($driver === 'sqlite') {
                (require database_path('migrations/2026_11_09_000001_make_bids_append_only.php'))->up();
            }
        }
    }

    private function backdateBid(Bid $bid, int $minutesAgo): void
    {
        $at = now()->subMinutes($minutesAgo);

        // The bid ledger refuses edits to created_at (migration 2026_11_09_000001). The demo world back-dates its history, so
        // for these statements, and only in this local-only seeder, the update guard is lifted and put back.
        $this->unlockBidHistory(fn () => DB::table('bids')->where('id', $bid->id)->update(['created_at' => $at, 'updated_at' => $at]));
    }

    // ───────────────────────────── settlements ─────────────────────────────

    /**
     * The sale states. With payment off a sale can only be awaiting payment with no deadline (the listener already wrote that), so
     * nothing is added. With `--payments` the states are reached the way they are live: a deadline from the payment window, the
     * real overdue and cancel services, and a paid row written directly (a gateway cannot be called). Notices come from the real
     * notification classes; only their timestamps are moved.
     */
    private function settlementStates(Auction $laptop, Auction $chairs, Auction $potjie, Auction $mower, Auction $rod, Auction $camera): void
    {
        $config = app(PaystackConfig::class);

        if (! $config->canTakePayments()) {
            return;
        }

        $window = $config->paymentWindowHours();

        // Awaiting payment, inside the deadline: the laptop (Sipho) and the camera (Johan).
        foreach ([$laptop, $camera] as $lot) {
            $s = $this->settlementFor($lot);
            $s->forceFill(['payment_due_at' => $s->created_at->copy()->addHours($window)])->save();
        }

        // Paid: the potjie (Aisha) and the fishing rod (Sipho).
        foreach ([$potjie, $rod] as $i => $lot) {
            $s = $this->settlementFor($lot);
            $paidAt = $s->created_at->copy()->addHours(5 + 7 * $i);
            $s->forceFill([
                'status' => AuctionSettlement::PAID,
                'payment_due_at' => $s->created_at->copy()->addHours($window),
                'paid_at' => $paidAt,
            ])->save();
            SettlementPayment::forceCreate([
                'settlement_id' => $s->id, 'provider' => 'paystack', 'reference' => 'AUC-DEMO-PAID-'.($i + 1),
                'amount_cents' => $s->amount_cents, 'currency' => $s->currency, 'status' => SettlementPayment::PAID,
                'provider_transaction_id' => '90000000'.($i + 1), 'initiated_by' => $s->winner_id,
                'paid_at' => $paidAt, 'summary' => 'Demo payment (no real money moved).',
                'created_at' => $paidAt->copy()->subMinutes(4), 'updated_at' => $paidAt,
            ]);
            $this->event($s, 'paid', 'Payment received.', $paidAt);
            $s->loadMissing(['auction', 'winner', 'seller']);
            $s->winner->notify(new SettlementPaidNotification($s, 'winner'));
            $s->seller->notify(new SettlementPaidNotification($s, 'seller'));
        }

        // Overdue: the chairs (Sipho) and the lawnmower (Lerato), whose deadlines passed. The real job marks them.
        foreach ([$chairs, $mower] as $lot) {
            $s = $this->settlementFor($lot);
            $s->forceFill(['payment_due_at' => $s->created_at->copy()->addHours($window)])->save();
        }
        app(SettlementLifecycle::class)->markOverdue();

        foreach ([$chairs, $mower] as $lot) {
            $s = $this->settlementFor($lot);
            $overdueAt = $s->payment_due_at->copy()->addMinutes(11);
            $s->forceFill(['overdue_at' => $overdueAt])->save();
            DB::table('settlement_events')->where('settlement_id', $s->id)->where('type', 'overdue')->update(['created_at' => $overdueAt]);
        }

        // Cancelled: the lawnmower. Pieter, its seller, gave up after the deadline, through the real cancel action.
        $s = $this->settlementFor($mower);
        app(SettlementLifecycle::class)->cancel($s, $this->users['pieter']);
        $cancelledAt = $s->fresh()->overdue_at->copy()->addHours(19)->addMinutes(7);
        DB::table('auction_settlements')->where('id', $s->id)->update(['cancelled_at' => $cancelledAt, 'updated_at' => $cancelledAt]);
        DB::table('settlement_events')->where('settlement_id', $s->id)->where('type', 'cancelled')->update(['created_at' => $cancelledAt]);
    }

    /**
     * Give every notice the time its cause really happened, plus a few minutes of the job's delay, so a feed reads as a week of
     * activity and not as one moment: a win when the lot closed, an outbid when the bid that displaced it arrived, a payment
     * notice when the payment, the deadline or the cancel happened. Only timestamps change; the copy was written by the real classes.
     */
    private function spreadNotificationTimes(): void
    {
        $ids = array_map(fn (User $u) => $u->id, $this->users);
        $rows = DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $ids)->get(['id', 'notifiable_id', 'data']);
        $auctions = DB::table('auctions')->pluck('ends_at', 'id');
        $settlements = DB::table('auction_settlements')->get()->keyBy('auction_id');

        foreach ($rows as $row) {
            $data = json_decode((string) $row->data, true) ?: [];
            $auctionId = $data['auction_id'] ?? null;
            $type = (string) ($data['type'] ?? '');
            $jitter = abs(crc32((string) $row->id));
            $settlement = $settlements->get($auctionId);

            $at = match (true) {
                in_array($type, ['auction_won', 'auction_sold', 'reserve_not_met'], true) && isset($auctions[$auctionId])
                    => Carbon::parse($auctions[$auctionId], 'UTC')->addMinutes(1 + $jitter % 8)->addSeconds($jitter % 50),
                $type === 'outbid' => $this->outbidAt((int) $auctionId, (int) $row->notifiable_id),
                $type === 'auction_ending_soon' => now()->subMinutes(14 + $jitter % 19),
                str_starts_with($type, 'settlement_paid') && $settlement?->paid_at => Carbon::parse($settlement->paid_at, 'UTC')->addMinutes(1 + $jitter % 3),
                str_starts_with($type, 'settlement_overdue') && $settlement?->overdue_at => Carbon::parse($settlement->overdue_at, 'UTC')->addMinutes(1 + $jitter % 4),
                $type === 'settlement_cancelled' && $settlement?->cancelled_at => Carbon::parse($settlement->cancelled_at, 'UTC')->addMinute(),
                default => null,
            };

            if ($at !== null) {
                DB::table('notifications')->where('id', $row->id)->update(['created_at' => $at, 'updated_at' => $at]);
            }
        }
    }

    /** When the newest bid by someone else on a lot arrived: the moment this person was outbid. */
    private function outbidAt(int $auctionId, int $userId): ?CarbonInterface
    {
        $at = DB::table('bids')->where('auction_id', $auctionId)->where('bidder_id', '!=', $userId)->max('created_at');

        return $at ? Carbon::parse($at, 'UTC')->addSeconds(2) : null;
    }

    private function settlementFor(Auction $auction): AuctionSettlement
    {
        return AuctionSettlement::query()->where('auction_id', $auction->id)->firstOrFail();
    }

    private function event(AuctionSettlement $s, string $type, string $note, CarbonInterface $at): void
    {
        $event = SettlementEvent::create(['settlement_id' => $s->id, 'type' => $type, 'note' => $note]);
        DB::table('settlement_events')->where('id', $event->id)->update(['created_at' => $at]);
    }

    private function rejectProposal(Auction $auction): void
    {
        // Mirrors ReserveDecisionPanel::reject(): the lot ends unsold and the proposal records the decision.
        DB::transaction(function () use ($auction) {
            $proposal = DB::table('reserve_not_met_proposals')->where('auction_id', $auction->id)->first();

            if ($proposal === null || $proposal->status !== 'pending') {
                return;
            }

            DB::table('auctions')->where('id', $auction->id)->update(['status' => 'ended']);
            DB::table('reserve_not_met_proposals')->where('id', $proposal->id)->update(['status' => 'rejected', 'decided_at' => now()->subDays(2)]);
        });
    }

    // ───────────────────────────── watchlists, notifications, operator ─────────────────────────────

    private function createWatchlists(): void
    {
        $watch = [
            'lerato' => ['Yamaha F310 acoustic guitar', 'Stainless steel gas braai, two burner', 'Wool rug, 2 m by 3 m', "Brass ship's lantern", 'Samsung 55-inch QLED television'],
            'sipho' => ['Samsung 55-inch QLED television', "Brass ship's lantern", 'Rocking chair, solid wood'],
            'aisha' => ['Mountain bike, 29-inch aluminium frame', 'Stainless steel gas braai, two burner', 'Set of four alloy wheels, 16-inch'],
            'johan' => ['Bosch cordless drill kit', 'Wool rug, 2 m by 3 m'],
        ];

        foreach ($watch as $key => $titles) {
            foreach ($titles as $title) {
                $auction = Auction::query()->where('title', $title)->first();

                if ($auction !== null) {
                    // withoutGlobalScopes: HasUserScope reads the signed-in user, and nobody is signed in here.
                    Watchlist::withoutGlobalScopes()->firstOrCreate(['user_id' => $this->users[$key]->id, 'auction_id' => $auction->id]);
                }
            }
        }
    }

    private function createExtraNotifications(): void
    {
        // Mark some of each user's older notices read, so the bell shows a believable mix.
        foreach (['lerato', 'sipho', 'aisha', 'johan', 'thandi', 'pieter'] as $key) {
            $ids = DB::table('notifications')
                ->where('notifiable_type', User::class)->where('notifiable_id', $this->users[$key]->id)
                ->orderBy('created_at')->pluck('id');

            $read = $ids->take((int) floor($ids->count() / 2));

            if ($read->isNotEmpty()) {
                DB::table('notifications')->whereIn('id', $read)->update(['read_at' => now()->subHours(3)]);
            }
        }
    }

    private function createOperatorEvidence(): void
    {
        // Payment attempts and payment events only exist when payment is on. With it off the operator page honestly has none.
        if (! app(PaystackConfig::class)->canTakePayments()) {
            return;
        }

        $s = $this->settlementFor(Auction::query()->where('title', 'Dell Latitude laptop, i5, 16 GB')->firstOrFail());

        // Sipho's sale is still inside its deadline: a card that was declined two hours ago, then a checkout he started and left open.
        SettlementPayment::forceCreate([
            'settlement_id' => $s->id, 'reference' => 'AUC-DEMO-FAIL-1', 'amount_cents' => $s->amount_cents, 'currency' => $s->currency,
            'status' => SettlementPayment::FAILED, 'initiated_by' => $s->winner_id, 'summary' => 'Card declined.',
            'created_at' => now()->subHours(2)->subMinutes(14), 'updated_at' => now()->subHours(2)->subMinutes(12),
        ]);
        SettlementPayment::forceCreate([
            'settlement_id' => $s->id, 'reference' => 'AUC-DEMO-INIT-1', 'amount_cents' => $s->amount_cents, 'currency' => $s->currency,
            'status' => SettlementPayment::INITIATED, 'initiated_by' => $s->winner_id, 'expires_at' => now()->addMinutes(20),
            'summary' => 'Checkout started.', 'created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10),
        ]);
        // Money Paystack reported for a reference this app never issued: the one thing that needs an operator.
        SettlementPayment::forceCreate([
            'settlement_id' => null, 'reference' => 'AUC-DEMO-UNMATCHED-1', 'amount_cents' => 125000, 'currency' => 'ZAR',
            'status' => SettlementPayment::UNMATCHED, 'reason' => 'unknown_reference',
            'provider_transaction_id' => '900000099', 'summary' => 'Money reported for a reference this app never issued.',
            'created_at' => now()->subHours(7)->subMinutes(31), 'updated_at' => now()->subHours(7)->subMinutes(31),
        ]);
    }

    // ───────────────────────────── photos ─────────────────────────────

    private function photos(Auction $auction, int $count, string $category): void
    {
        if ($count < 1) {
            return;
        }

        $files = [];
        $temporary = [];

        for ($i = 1; $i <= $count; $i++) {
            $path = tempnam(sys_get_temp_dir(), 'auction-demo-').'.jpg';
            $temporary[] = $path;
            $this->drawPlaceholder($path, $auction->title, $category, $i, $count);
            $files[] = new UploadedFile($path, Str::slug($auction->title).'-'.$i.'.jpg', 'image/jpeg', null, true);
        }

        try {
            app(AuctionImageManager::class)->add($auction, $files);
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
                @unlink(Str::beforeLast($path, '.jpg'));
            }
        }
    }

    /**
     * A neutral stand-in photo: a flat studio wall, a floor band and one plain silhouette for the kind of thing (a screen, a table, a
     * frame, a wheel...). No words, nothing that says demo, nothing that pretends to be a real item. Each photo of a lot moves the
     * silhouette a little, so a gallery of three is three different pictures. Production lots show their sellers' real photos.
     */
    private function drawPlaceholder(string $path, string $title, string $category, int $index, int $total): void
    {
        $palette = [
            'electronics' => [[206, 213, 222], [64, 79, 98]], 'home-furniture' => [[222, 212, 198], [112, 84, 58]],
            'art-collectables' => [[218, 206, 210], [122, 62, 82]], 'vehicles-parts' => [[210, 213, 216], [70, 76, 86]],
            'outdoors-sport' => [[205, 218, 208], [54, 98, 74]], 'jewellery-watches' => [[224, 218, 200], [138, 112, 52]],
            'books-music' => [[214, 208, 224], [84, 64, 112]], 'tools-equipment' => [[224, 210, 196], [140, 84, 44]],
        ];
        [$wall, $ink] = $palette[$category] ?? [[212, 212, 216], [76, 76, 84]];
        $seed = ++$this->photoSeed;
        $w = 1280;
        $h = 960;
        $im = imagecreatetruecolor($w, $h);
        $mix = fn (array $c, array $to, float $t) => imagecolorallocate($im, (int) ($c[0] + ($to[0] - $c[0]) * $t), (int) ($c[1] + ($to[1] - $c[1]) * $t), (int) ($c[2] + ($to[2] - $c[2]) * $t));
        $wallC = $mix($wall, [255, 255, 255], 0.12 * (($seed + $index) % 3));
        $floorC = $mix($wall, $ink, 0.18);
        $shadowC = $mix($wall, $ink, 0.34);
        $bodyC = $mix($ink, $wall, 0.12);
        $faceC = $mix($ink, $wall, 0.62);
        $lineC = $mix($ink, $wall, 0.32);

        imagefilledrectangle($im, 0, 0, $w, $h, $wallC);
        $horizon = 640;
        imagefilledrectangle($im, 0, $horizon, $w, $h, $floorC);

        // Where the object stands: left, centre or right of the frame, a little different in each photo of the lot.
        $shift = [0, -120, 130, 60, -60][($seed + $index) % 5];
        $cx = (int) ($w / 2) + $shift;
        $scale = 1 - 0.08 * (($index - 1) % 3);
        $u = fn (float $n): int => (int) round($n * $scale);

        imagefilledellipse($im, $cx, $horizon + 70, $u(620), $u(70), $shadowC);

        switch ($category) {
            case 'electronics':
                imagefilledrectangle($im, $cx - $u(300), $horizon - $u(360), $cx + $u(300), $horizon - $u(40), $bodyC);
                imagefilledrectangle($im, $cx - $u(276), $horizon - $u(336), $cx + $u(276), $horizon - $u(64), $faceC);
                imagefilledrectangle($im, $cx - $u(80), $horizon - $u(40), $cx + $u(80), $horizon - $u(8), $bodyC);
                imagefilledrectangle($im, $cx - $u(150), $horizon - $u(8), $cx + $u(150), $horizon, $bodyC);
                break;
            case 'home-furniture':
                imagefilledrectangle($im, $cx - $u(320), $horizon - $u(280), $cx + $u(320), $horizon - $u(236), $bodyC);
                foreach ([-290, 262] as $x) {
                    imagefilledrectangle($im, $cx + $u($x), $horizon - $u(236), $cx + $u($x) + $u(28), $horizon + $u(10), $bodyC);
                }
                imagefilledrectangle($im, $cx - $u(250), $horizon - $u(236), $cx + $u(250), $horizon - $u(206), $faceC);
                break;
            case 'art-collectables':
                imagefilledrectangle($im, $cx - $u(250), $horizon - $u(420), $cx + $u(250), $horizon - $u(40), $bodyC);
                imagefilledrectangle($im, $cx - $u(214), $horizon - $u(384), $cx + $u(214), $horizon - $u(76), $faceC);
                imagefilledpolygon($im, [$cx - $u(214), $horizon - $u(76), $cx - $u(40), $horizon - $u(250), $cx + $u(60), $horizon - $u(150), $cx + $u(130), $horizon - $u(210), $cx + $u(214), $horizon - $u(120), $cx + $u(214), $horizon - $u(76)], $lineC);
                break;
            case 'vehicles-parts':
                foreach ([-190, 190] as $x) {
                    imagefilledellipse($im, $cx + $u($x), $horizon - $u(120), $u(240), $u(240), $bodyC);
                    imagefilledellipse($im, $cx + $u($x), $horizon - $u(120), $u(130), $u(130), $faceC);
                    imagefilledellipse($im, $cx + $u($x), $horizon - $u(120), $u(36), $u(36), $bodyC);
                }
                imagefilledrectangle($im, $cx - $u(190), $horizon - $u(130), $cx + $u(190), $horizon - $u(100), $bodyC);
                break;
            case 'outdoors-sport':
                imagefilledpolygon($im, [$cx - $u(330), $horizon, $cx, $horizon - $u(400), $cx + $u(330), $horizon], $bodyC);
                imagefilledpolygon($im, [$cx - $u(60), $horizon, $cx, $horizon - $u(190), $cx + $u(60), $horizon], $faceC);
                break;
            case 'jewellery-watches':
                imagefilledellipse($im, $cx, $horizon - $u(230), $u(330), $u(330), $bodyC);
                imagefilledellipse($im, $cx, $horizon - $u(230), $u(262), $u(262), $faceC);
                imagefilledrectangle($im, $cx - $u(18), $horizon - $u(250), $cx + $u(4), $horizon - $u(228), $bodyC);
                imagefilledrectangle($im, $cx - $u(12), $horizon - $u(396), $cx + $u(12), $horizon - $u(360), $bodyC);
                imagefilledrectangle($im, $cx - $u(42), $horizon - $u(410), $cx + $u(42), $horizon - $u(394), $bodyC);
                break;
            case 'books-music':
                $y = $horizon;
                foreach ([[560, 70], [500, 62], [540, 58], [470, 66]] as $i => [$wd, $ht]) {
                    imagefilledrectangle($im, $cx - $u($wd / 2) + $u(($i % 2) * 26), $y - $u($ht), $cx + $u($wd / 2) + $u(($i % 2) * 26), $y, $i % 2 ? $bodyC : $lineC);
                    imagefilledrectangle($im, $cx - $u($wd / 2) + $u(($i % 2) * 26), $y - $u($ht) + $u(12), $cx + $u($wd / 2) + $u(($i % 2) * 26), $y - $u($ht) + $u(20), $faceC);
                    $y -= $u($ht);
                }
                break;
            default: // tools and anything else: a handle and a head
                imagefilledrectangle($im, $cx - $u(18), $horizon - $u(430), $cx + $u(18), $horizon, $bodyC);
                imagefilledrectangle($im, $cx - $u(190), $horizon - $u(430), $cx + $u(190), $horizon - $u(350), $bodyC);
                imagefilledrectangle($im, $cx - $u(160), $horizon - $u(412), $cx + $u(160), $horizon - $u(368), $faceC);
        }

        imagejpeg($im, $path, 84);
    }

    private function say(string $line): void
    {
        $this->command?->info($line);
    }
}
