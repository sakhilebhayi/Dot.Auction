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

    public function __construct(private readonly bool $fresh = false) {}

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

        $this->purge();
        $this->createPeople();
        $this->createCategories();
        $this->createLots();
        $this->createWatchlists();
        $this->createExtraNotifications();
        $this->createOperatorEvidence();
        $this->createZanele();

        $this->say('Demo world ready. Sign in as any demo user with the password "'.self::PASSWORD.'" or use /dev/login/{email}.');
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
            'photos' => 1, 'openedAgo' => '9 days', 'closedAgo' => '2 days',
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
            $this->backdate($auction, now()->subDays(2));

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
            return app(ListingManager::class)->cancel($auction);
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
        DB::table('notifications')->where('data', 'like', '%"auction_id":'.$auction->id.'%')->update(['created_at' => $at, 'updated_at' => $at]);
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

    private function settlementStates(Auction $laptop, Auction $chairs, Auction $potjie, Auction $mower, Auction $rod, Auction $camera): void
    {
        $window = (int) config('auction.payment_window_hours', 72);
        // "Payment opened" and "deadline passed" are only true while online payment is switched on. With no Paystack key the sales
        // are recorded as awaiting payment and the pages say payment opens soon, so the history must not claim otherwise.
        $paymentOn = app(PaystackConfig::class)->canTakePayments();

        // Pending (awaiting payment): the laptop (Sipho) and the camera (Johan).
        foreach ([$laptop, $camera] as $lot) {
            $s = $this->settlementFor($lot);
            $due = $s->created_at->copy()->addHours($window);
            $s->forceFill(['payment_due_at' => $due])->save();
            $paymentOn && $this->event($s, 'payment_opened', 'Payment opened.', $s->created_at);
        }

        // Overdue (still payable): the chairs, due two days ago. Both sides told through the real notification.
        $s = $this->settlementFor($chairs);
        $s->forceFill([
            'status' => AuctionSettlement::PAYMENT_OVERDUE,
            'payment_due_at' => now()->subDays(1),
            'overdue_at' => now()->subDays(1),
        ])->save();
        $paymentOn && $this->event($s, 'overdue', 'Payment deadline passed.', now()->subDay());
        if ($paymentOn) {
            $s->loadMissing(['auction', 'winner', 'seller']);
            $s->winner->notify(new SettlementOverdueNotification($s, 'winner'));
            $s->seller->notify(new SettlementOverdueNotification($s, 'seller'));
        }

        // Paid: the potjie (Aisha) and the fishing rod (Sipho).
        foreach ([$potjie, $rod] as $i => $lot) {
            $s = $this->settlementFor($lot);
            $paidAt = $s->created_at->copy()->addHours(5);
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
            ]);
            $this->event($s, 'paid', 'Paid.', $paidAt);
            $s->loadMissing(['auction', 'winner', 'seller']);
            $s->winner->notify(new SettlementPaidNotification($s, 'winner'));
            $s->seller->notify(new SettlementPaidNotification($s, 'seller'));
        }

        // Cancelled: the lawnmower (Lerato). Overdue first, then the seller's real cancel action.
        $s = $this->settlementFor($mower);
        $s->forceFill([
            'status' => AuctionSettlement::PAYMENT_OVERDUE,
            'payment_due_at' => $s->created_at->copy()->addHours($window),
            'overdue_at' => $s->created_at->copy()->addHours($window),
        ])->save();
        $this->event($s, 'overdue', 'Payment deadline passed.', $s->created_at->copy()->addHours($window));
        app(SettlementLifecycle::class)->cancel($s, $this->users['pieter']);
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
        $s = $this->settlementFor(Auction::query()->where('title', 'Dell Latitude laptop, i5, 16 GB')->firstOrFail());

        // A started checkout, a failed attempt, and a payment event that matched no sale (needs an operator).
        SettlementPayment::forceCreate([
            'settlement_id' => $s->id, 'reference' => 'AUC-DEMO-INIT-1', 'amount_cents' => $s->amount_cents, 'currency' => $s->currency,
            'status' => SettlementPayment::INITIATED, 'initiated_by' => $s->winner_id, 'expires_at' => now()->addMinutes(20),
            'summary' => 'Checkout started.',
        ]);
        SettlementPayment::forceCreate([
            'settlement_id' => $s->id, 'reference' => 'AUC-DEMO-FAIL-1', 'amount_cents' => $s->amount_cents, 'currency' => $s->currency,
            'status' => SettlementPayment::FAILED, 'initiated_by' => $s->winner_id, 'summary' => 'Card declined.',
        ]);
        SettlementPayment::forceCreate([
            'settlement_id' => null, 'reference' => 'AUC-DEMO-UNMATCHED-1', 'amount_cents' => 125000, 'currency' => 'ZAR',
            'status' => SettlementPayment::UNMATCHED, 'reason' => 'unknown_reference',
            'provider_transaction_id' => '900000099', 'summary' => 'Money reported for a reference this app never issued.',
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
     * A neutral placeholder: a flat colour per category, a diagonal pattern, and the words
     * "DEMO IMAGE" so nobody mistakes it for a photo of a real item.
     */
    private function drawPlaceholder(string $path, string $title, string $category, int $index, int $total): void
    {
        $palette = [
            'electronics' => [38, 58, 84], 'home-furniture' => [86, 66, 48], 'art-collectables' => [96, 52, 66],
            'vehicles-parts' => [58, 62, 70], 'outdoors-sport' => [44, 78, 62], 'jewellery-watches' => [96, 82, 44],
            'books-music' => [72, 54, 92], 'tools-equipment' => [92, 60, 36],
        ];
        [$r, $g, $b] = $palette[$category] ?? [60, 60, 66];
        $shift = (++$this->photoSeed % 3) * 12;

        $w = 320;
        $h = 240;
        $small = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($small, min(255, $r + $shift), min(255, $g + $shift), min(255, $b + $shift));
        $line = imagecolorallocate($small, min(255, $r + $shift + 18), min(255, $g + $shift + 18), min(255, $b + $shift + 18));
        $ink = imagecolorallocate($small, 235, 235, 238);
        imagefilledrectangle($small, 0, 0, $w, $h, $bg);

        for ($x = -$h; $x < $w; $x += 24) {
            imageline($small, $x, $h, $x + $h, 0, $line);
        }

        $label = 'DEMO IMAGE';
        imagestring($small, 5, (int) (($w - strlen($label) * 9) / 2), 96, $label, $ink);
        $caption = Str::limit($title, 34, '');
        imagestring($small, 2, (int) (($w - strlen($caption) * 6) / 2), 124, $caption, $ink);
        $page = 'photo '.$index.' of '.$total;
        imagestring($small, 2, (int) (($w - strlen($page) * 6) / 2), 142, $page, $ink);

        // Enlarge so the text is chunky and the file looks like a real listing photo size.
        $big = imagecreatetruecolor(1280, 960);
        imagecopyresampled($big, $small, 0, 0, 0, 0, 1280, 960, $w, $h);
        imagejpeg($big, $path, 82);
    }

    private function say(string $line): void
    {
        $this->command?->info($line);
    }
}
