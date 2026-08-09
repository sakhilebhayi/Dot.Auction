# Auction Settlement + Reserve-Not-Met Approval Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build this platform's first-ever scheduled process — an auction settlement sweep — split so that resolving an auction whose top bid clears reserve happens automatically (Level 1), while resolving one whose top bid falls short of reserve stops and waits for the seller's own decision (Level 2).

**Architecture:** A new `auction:settle-ended` console command (this repo's first scheduled job) processes `active` auctions past `ends_at`. No bids, or a top bid clearing (or no) reserve → settles directly via the model's existing `reserveMet()` method, notifies the winner, dispatches `AuctionSettled`. Top bid below reserve → flips the auction to a new `pending_review` status, creates a `ReserveNotMetProposal`, dispatches `ReserveNotMet`, and stops. A new `ReserveDecisionPanel` Livewire component (mirroring `BidPanel`'s existing shape) lets the auction's own seller — gated by a new `ReserveNotMetProposalPolicy`, reusing the seller-ownership check `AuctionPolicy` already uses — accept (settle to that bid) or reject (end unsold) from their dashboard.

**Tech Stack:** Laravel 13 (pgsql in production, sqlite in tests), Livewire, PHPUnit.

## Global Constraints

- No `app/Services` or custom `app/Actions` layer exists in this repo — domain logic stays inline in the console command and the Livewire component's own methods, matching `BidPanel::placeBid()`'s and `AuctionController`'s existing directness. Do not introduce a new layering this codebase doesn't already use.
- Reuse `Auction::reserveMet(): bool` (`app/Models/Auction.php:85`) for the reserve comparison — do not re-derive `topBid->amount >= reserve_price` separately; `current_price` is already kept in sync with the winning bid's amount by `BidPanel::placeBid()`, so `reserveMet()` is exactly the right check and already handles the null-reserve case.
- Reuse `Auction::winningBid` (`hasOne(Bid::class)->where('is_winning', true)`, `app/Models/Auction.php`) for "the top bid" — do not write a new query for it.
- No new role/flag — the reviewer for a `ReserveNotMetProposal` is always `$proposal->auction->seller_id`, mirroring `AuctionPolicy::bid()`'s existing `$user->id === $auction->seller_id` check exactly.
- `Gate::authorize()` as the first line of every mutating Livewire method, matching `AuctionController`'s existing `Gate::authorize('viewAny'|'view', ...)` convention.
- Migration technique for widening `auctions.status`: conditionally drop the pgsql `CHECK` constraint (`auctions_status_check`), then `->change()` the column to a plain string. Matches `Dot.Tutor/database/migrations/2026_08_09_000001_widen_tutor_sessions_status_column.php` exactly. SQLite (used in tests) has no such constraint — Laravel's schema builder rebuilds the table automatically on `->change()`, no conditional needed for it.
- Tests use `Auction::create()`/`Bid::create()` directly — this repo has no `AuctionFactory`/`BidFactory` and none are introduced here; matches `tests/Feature/Auctions/BidPanelTest.php`'s established convention exactly (including its `makeAuction()` private helper shape).
- Per this repo's own `CLAUDE.md` Laravel Boost guidelines ("Verification Scripts" section): do not create verification scripts or use `tinker` when tests cover the functionality and prove it works.
- Run `vendor/bin/pint --dirty --format agent` after every task before committing.

---

### Task 1: Migration + `reserve_not_met_proposals` table + `ReserveNotMetProposal` model

**Files:**
- Create: `database/migrations/2026_08_09_000001_widen_auctions_status_column.php`
- Create: `database/migrations/2026_08_09_000002_create_reserve_not_met_proposals_table.php`
- Create: `app/Models/ReserveNotMetProposal.php`
- Test: `tests/Unit/Models/ReserveNotMetProposalTest.php`

**Interfaces:**
- Produces: `auctions.status` accepting any string (structurally: `'draft'|'active'|'ended'|'cancelled'|'pending_review'`); `ReserveNotMetProposal` model with `$fillable = ['auction_id', 'bid_id', 'reserve_price', 'status', 'decided_at']`, relations `auction(): BelongsTo` and `bid(): BelongsTo`.

- [ ] **Step 1: Write the widening migration**

Create `database/migrations/2026_08_09_000001_widen_auctions_status_column.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE auctions DROP CONSTRAINT IF EXISTS auctions_status_check');
        }

        Schema::table('auctions', function (Blueprint $table) {
            $table->string('status')->default('draft')->change();
        });
    }

    public function down(): void
    {
        // Widening to a plain string is not meaningfully reversible back to
        // a narrower native enum without knowing every value already
        // stored -- intentionally a no-op, matching this program's
        // established convention for this exact migration shape.
    }
};
```

- [ ] **Step 2: Write the `reserve_not_met_proposals` migration**

Create `database/migrations/2026_08_09_000002_create_reserve_not_met_proposals_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserve_not_met_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bid_id')->constrained()->cascadeOnDelete();
            $table->decimal('reserve_price', 12, 2);
            $table->string('status')->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserve_not_met_proposals');
    }
};
```

- [ ] **Step 3: Run migrations**

Run: `php artisan migrate`
Expected: both migrations run without error.

- [ ] **Step 4: Write the model**

Create `app/Models/ReserveNotMetProposal.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReserveNotMetProposal extends Model
{
    protected $fillable = [
        'auction_id', 'bid_id', 'reserve_price', 'status', 'decided_at',
    ];

    protected $casts = [
        'reserve_price' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }
}
```

- [ ] **Step 5: Write a model test**

Create `tests/Unit/Models/ReserveNotMetProposalTest.php`:

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReserveNotMetProposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_an_auction_and_a_bid(): void
    {
        $seller = User::factory()->create();
        $bidder = User::factory()->create();

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'title' => 'Vintage Camera',
            'starting_price' => 100,
            'reserve_price' => 500,
            'current_price' => 300,
            'bid_increment' => 10,
            'status' => 'pending_review',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subMinute(),
        ]);

        $bid = Bid::create([
            'auction_id' => $auction->id,
            'bidder_id' => $bidder->id,
            'amount' => 300,
            'is_winning' => true,
        ]);

        $proposal = ReserveNotMetProposal::create([
            'auction_id' => $auction->id,
            'bid_id' => $bid->id,
            'reserve_price' => 500,
            'status' => 'pending',
        ]);

        $this->assertTrue($proposal->auction->is($auction));
        $this->assertTrue($proposal->bid->is($bid));
        $this->assertSame('pending', $proposal->status);
    }
}
```

- [ ] **Step 6: Run the test**

Run: `php artisan test --compact tests/Unit/Models/ReserveNotMetProposalTest.php`
Expected: PASS, 1 test.

- [ ] **Step 7: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_09_000001_widen_auctions_status_column.php \
  database/migrations/2026_08_09_000002_create_reserve_not_met_proposals_table.php \
  app/Models/ReserveNotMetProposal.php \
  tests/Unit/Models/ReserveNotMetProposalTest.php
git commit -m "$(cat <<'EOF'
feat: widen auctions.status + add reserve_not_met_proposals table

Widens auctions.status to a plain string so a new pending_review
value fits without a native-enum migration dance (pgsql CHECK
constraint dropped conditionally; sqlite rebuilds automatically).
Adds reserve_not_met_proposals + ReserveNotMetProposal, the record
created when a lot ends below reserve -- see
docs/superpowers/specs/2026-08-09-auction-settlement-reserve-gate.md.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: `SettleEndedAuctions` command + events + schedule

**Files:**
- Create: `app/Events/AuctionSettled.php`
- Create: `app/Events/ReserveNotMet.php`
- Create: `app/Console/Commands/SettleEndedAuctions.php`
- Modify: `routes/console.php` (add the schedule entry)
- Test: `tests/Feature/Console/SettleEndedAuctionsCommandTest.php`

**Interfaces:**
- Consumes: `Auction::winningBid` (existing relation), `Auction::reserveMet()` (existing method), `ReserveNotMetProposal::create()` (Task 1), `App\Notifications\AuctionWonNotification` (existing).
- Produces: `App\Events\AuctionSettled(Auction $auction, ?Bid $winningBid)`, `App\Events\ReserveNotMet(ReserveNotMetProposal $proposal)`, Artisan command `auction:settle-ended`.

- [ ] **Step 1: Write the failing command test**

Create `tests/Feature/Console/SettleEndedAuctionsCommandTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Events\AuctionSettled;
use App\Events\ReserveNotMet;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\User;
use App\Notifications\AuctionWonNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SettleEndedAuctionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeEndedAuction(array $overrides = []): Auction
    {
        $seller = User::factory()->create();

        return Auction::create(array_merge([
            'seller_id' => $seller->id,
            'title' => 'Antique Clock',
            'starting_price' => 100,
            'current_price' => 100,
            'bid_increment' => 10,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subMinute(),
        ], $overrides));
    }

    public function test_an_auction_with_no_bids_ends_unsold(): void
    {
        Event::fake([AuctionSettled::class]);
        $auction = $this->makeEndedAuction();

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $this->assertSame('ended', $auction->fresh()->status);
        $this->assertDatabaseCount('reserve_not_met_proposals', 0);
        Event::assertDispatched(AuctionSettled::class, fn ($e) => $e->auction->is($auction) && $e->winningBid === null);
    }

    public function test_an_auction_with_reserve_met_settles_and_notifies_the_winner(): void
    {
        Notification::fake();
        Event::fake([AuctionSettled::class]);
        $auction = $this->makeEndedAuction(['reserve_price' => 150, 'current_price' => 200]);
        $bidder = User::factory()->create();
        $bid = Bid::create([
            'auction_id' => $auction->id,
            'bidder_id' => $bidder->id,
            'amount' => 200,
            'is_winning' => true,
        ]);

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $this->assertSame('ended', $auction->fresh()->status);
        Notification::assertSentTo($bidder, AuctionWonNotification::class);
        Event::assertDispatched(AuctionSettled::class, fn ($e) => $e->auction->is($auction) && $e->winningBid->is($bid));
    }

    public function test_an_auction_with_no_reserve_settles_like_reserve_met(): void
    {
        Notification::fake();
        $auction = $this->makeEndedAuction(['current_price' => 120]);
        $bidder = User::factory()->create();
        Bid::create([
            'auction_id' => $auction->id,
            'bidder_id' => $bidder->id,
            'amount' => 120,
            'is_winning' => true,
        ]);

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $this->assertSame('ended', $auction->fresh()->status);
        Notification::assertSentTo($bidder, AuctionWonNotification::class);
    }

    public function test_an_auction_with_reserve_not_met_creates_a_proposal_and_does_not_notify(): void
    {
        Notification::fake();
        Event::fake([ReserveNotMet::class]);
        $auction = $this->makeEndedAuction(['reserve_price' => 500, 'current_price' => 300]);
        $bidder = User::factory()->create();
        $bid = Bid::create([
            'auction_id' => $auction->id,
            'bidder_id' => $bidder->id,
            'amount' => 300,
            'is_winning' => true,
        ]);

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $this->assertSame('pending_review', $auction->fresh()->status);
        $this->assertDatabaseHas('reserve_not_met_proposals', [
            'auction_id' => $auction->id,
            'bid_id' => $bid->id,
            'reserve_price' => 500,
            'status' => 'pending',
        ]);
        Notification::assertNothingSent();
        Event::assertDispatched(ReserveNotMet::class, fn ($e) => $e->proposal->auction_id === $auction->id);
    }

    public function test_an_auction_not_yet_past_ends_at_is_left_untouched(): void
    {
        $auction = $this->makeEndedAuction(['ends_at' => now()->addDay()]);

        $this->artisan('auction:settle-ended')->assertSuccessful();

        $this->assertSame('active', $auction->fresh()->status);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Console/SettleEndedAuctionsCommandTest.php`
Expected: FAIL — command `auction:settle-ended` does not exist yet
("Command \"auction:settle-ended\" is not defined" or similar Artisan
error on every test).

- [ ] **Step 3: Write the events**

Create `app/Events/AuctionSettled.php`:

```php
<?php

namespace App\Events;

use App\Models\Auction;
use App\Models\Bid;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AuctionSettled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Auction $auction,
        public readonly ?Bid $winningBid,
    ) {}
}
```

Create `app/Events/ReserveNotMet.php`:

```php
<?php

namespace App\Events;

use App\Models\ReserveNotMetProposal;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReserveNotMet
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly ReserveNotMetProposal $proposal) {}
}
```

- [ ] **Step 4: Write the command**

Create `app/Console/Commands/SettleEndedAuctions.php`:

```php
<?php

namespace App\Console\Commands;

use App\Events\AuctionSettled;
use App\Events\ReserveNotMet;
use App\Models\Auction;
use App\Models\ReserveNotMetProposal;
use App\Notifications\AuctionWonNotification;
use Illuminate\Console\Command;

class SettleEndedAuctions extends Command
{
    protected $signature = 'auction:settle-ended';

    protected $description = 'Settle active auctions whose bidding window has closed; reserve-not-met lots stop for seller review instead of auto-settling.';

    public function handle(): int
    {
        $dueAuctions = Auction::where('status', 'active')
            ->where('ends_at', '<=', now())
            ->get();

        foreach ($dueAuctions as $auction) {
            try {
                $this->settle($auction);
            } catch (\Throwable $e) {
                $this->error("Failed to settle auction #{$auction->id}: {$e->getMessage()}");
            }
        }

        $this->info("Processed {$dueAuctions->count()} ended auction(s).");

        return self::SUCCESS;
    }

    private function settle(Auction $auction): void
    {
        $topBid = $auction->winningBid;

        if ($topBid === null) {
            $auction->update(['status' => 'ended']);
            event(new AuctionSettled($auction, null));

            return;
        }

        if ($auction->reserveMet()) {
            $auction->update(['status' => 'ended']);
            $topBid->bidder->notify(new AuctionWonNotification($auction));
            event(new AuctionSettled($auction, $topBid));

            return;
        }

        $auction->update(['status' => 'pending_review']);

        $proposal = ReserveNotMetProposal::create([
            'auction_id' => $auction->id,
            'bid_id' => $topBid->id,
            'reserve_price' => $auction->reserve_price,
            'status' => 'pending',
        ]);

        event(new ReserveNotMet($proposal));
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Console/SettleEndedAuctionsCommandTest.php`
Expected: PASS, 5 tests, 0 failures.

- [ ] **Step 6: Add the schedule entry**

`routes/console.php` currently has no `Schedule::` calls at all. Add, after
the existing `Artisan::command('inspire', ...)` block:

```php
use App\Console\Commands\SettleEndedAuctions;
use Illuminate\Support\Facades\Schedule;

// ─── Scheduled Platform Jobs ──────────────────────────────────────────────────
// This platform's first scheduled process -- see
// docs/superpowers/specs/2026-08-09-auction-settlement-reserve-gate.md.
Schedule::command(SettleEndedAuctions::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
```

(Add the two `use` statements to the existing top-of-file import block
rather than inline.)

- [ ] **Step 7: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add app/Events/AuctionSettled.php app/Events/ReserveNotMet.php \
  app/Console/Commands/SettleEndedAuctions.php routes/console.php \
  tests/Feature/Console/SettleEndedAuctionsCommandTest.php
git commit -m "$(cat <<'EOF'
feat: auction:settle-ended command -- this platform's first scheduler entry

Settles active auctions past ends_at: no bids, or reserve met (via
the existing Auction::reserveMet()), settles directly and notifies
the winner (AuctionWonNotification, previously written but never
wired to any trigger). Reserve not met stops instead of guessing --
flips the auction to pending_review and creates a
ReserveNotMetProposal for the seller to decide, dispatching the
auction.reserve.not_met event wiki.md #6 already names as owed.

Scheduled every 5 minutes in routes/console.php, this platform's
first Schedule:: entry.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `ReserveNotMetProposalPolicy` + `ReserveDecisionPanel` + dashboard wiring

**Files:**
- Create: `app/Policies/ReserveNotMetProposalPolicy.php`
- Create: `app/Livewire/Auctions/ReserveDecisionPanel.php`
- Create: `resources/views/livewire/auctions/reserve-decision-panel.blade.php`
- Modify: `routes/web.php` (dashboard closure: fetch the seller's pending-review proposals)
- Modify: `resources/views/dashboard.blade.php` (render the panel per pending proposal)
- Test: `tests/Feature/Auctions/ReserveDecisionPanelTest.php`

**Interfaces:**
- Consumes: `ReserveNotMetProposal` (Task 1), `AuctionWonNotification` (existing).
- Produces: `Gate`-checkable ability `review` on `ReserveNotMetProposal`; Livewire component `auctions.reserve-decision-panel` taking a `proposal` prop.

- [ ] **Step 1: Write the failing Livewire test**

Create `tests/Feature/Auctions/ReserveDecisionPanelTest.php`:

```php
<?php

namespace Tests\Feature\Auctions;

use App\Livewire\Auctions\ReserveDecisionPanel;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\ReserveNotMetProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use App\Notifications\AuctionWonNotification;
use Livewire\Livewire;
use Tests\TestCase;

class ReserveDecisionPanelTest extends TestCase
{
    use RefreshDatabase;

    private function makeProposal(): array
    {
        $seller = User::factory()->create();
        $bidder = User::factory()->create();

        $auction = Auction::create([
            'seller_id' => $seller->id,
            'title' => 'Rare Coin',
            'starting_price' => 50,
            'reserve_price' => 500,
            'current_price' => 300,
            'bid_increment' => 10,
            'status' => 'pending_review',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subMinute(),
        ]);

        $bid = Bid::create([
            'auction_id' => $auction->id,
            'bidder_id' => $bidder->id,
            'amount' => 300,
            'is_winning' => true,
        ]);

        $proposal = ReserveNotMetProposal::create([
            'auction_id' => $auction->id,
            'bid_id' => $bid->id,
            'reserve_price' => 500,
            'status' => 'pending',
        ]);

        return compact('seller', 'bidder', 'auction', 'bid', 'proposal');
    }

    public function test_the_seller_can_accept_the_top_bid(): void
    {
        Notification::fake();
        ['seller' => $seller, 'bidder' => $bidder, 'auction' => $auction, 'proposal' => $proposal] = $this->makeProposal();

        Livewire::actingAs($seller)
            ->test(ReserveDecisionPanel::class, ['proposal' => $proposal])
            ->call('accept');

        $this->assertSame('ended', $auction->fresh()->status);
        $this->assertSame('accepted', $proposal->fresh()->status);
        $this->assertNotNull($proposal->fresh()->decided_at);
        Notification::assertSentTo($bidder, AuctionWonNotification::class);
    }

    public function test_the_seller_can_reject_the_top_bid(): void
    {
        Notification::fake();
        ['seller' => $seller, 'bidder' => $bidder, 'auction' => $auction, 'proposal' => $proposal] = $this->makeProposal();

        Livewire::actingAs($seller)
            ->test(ReserveDecisionPanel::class, ['proposal' => $proposal])
            ->call('reject');

        $this->assertSame('ended', $auction->fresh()->status);
        $this->assertSame('rejected', $proposal->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_a_non_seller_is_forbidden(): void
    {
        ['proposal' => $proposal] = $this->makeProposal();
        $someoneElse = User::factory()->create();

        Livewire::actingAs($someoneElse)
            ->test(ReserveDecisionPanel::class, ['proposal' => $proposal])
            ->call('accept')
            ->assertForbidden();

        $this->assertSame('pending', $proposal->fresh()->status);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Auctions/ReserveDecisionPanelTest.php`
Expected: FAIL — `App\Livewire\Auctions\ReserveDecisionPanel` doesn't exist
(`ComponentNotFoundException` or class-not-found error on all 3 tests).

- [ ] **Step 3: Write the policy**

Create `app/Policies/ReserveNotMetProposalPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\ReserveNotMetProposal;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReserveNotMetProposalPolicy
{
    use HandlesAuthorization;

    /**
     * Only the auction's own seller may decide a reserve-not-met proposal
     * -- mirrors AuctionPolicy::bid()'s existing seller-ownership check.
     */
    public function review(User $user, ReserveNotMetProposal $proposal): bool
    {
        return $user->id === $proposal->auction->seller_id;
    }
}
```

- [ ] **Step 4: Write the Livewire component**

Create `app/Livewire/Auctions/ReserveDecisionPanel.php`:

```php
<?php

namespace App\Livewire\Auctions;

use App\Models\ReserveNotMetProposal;
use App\Notifications\AuctionWonNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

class ReserveDecisionPanel extends Component
{
    public ReserveNotMetProposal $proposal;

    public function mount(ReserveNotMetProposal $proposal): void
    {
        $this->proposal = $proposal;
    }

    public function accept(): void
    {
        Gate::authorize('review', $this->proposal);

        $bid = $this->proposal->bid;

        $this->proposal->auction->update(['status' => 'ended']);
        $bid->bidder->notify(new AuctionWonNotification($this->proposal->auction));
        $this->proposal->update(['status' => 'accepted', 'decided_at' => now()]);
    }

    public function reject(): void
    {
        Gate::authorize('review', $this->proposal);

        $this->proposal->auction->update(['status' => 'ended']);
        $this->proposal->update(['status' => 'rejected', 'decided_at' => now()]);
    }

    public function render(): View
    {
        return view('livewire.auctions.reserve-decision-panel');
    }
}
```

- [ ] **Step 5: Write the Blade view**

Create `resources/views/livewire/auctions/reserve-decision-panel.blade.php`:

```blade
<div class="reserve-decision-panel" style="border:1px solid #e5c07b;border-radius:0.5rem;padding:1rem;margin-bottom:0.75rem;">
    @if ($proposal->status === 'pending')
        <p style="font-weight:600;">"{{ $proposal->auction->title }}" ended below reserve.</p>
        <p style="font-size:0.85rem;color:#6b5b4a;">
            Top bid R{{ number_format((float) $proposal->bid->amount, 2) }}
            vs reserve R{{ number_format((float) $proposal->reserve_price, 2) }}.
        </p>
        <div style="display:flex;gap:0.5rem;margin-top:0.5rem;">
            <button wire:click="accept" wire:confirm="Accept R{{ number_format((float) $proposal->bid->amount, 2) }} from {{ $proposal->bid->bidder->name }}?">
                Accept top bid
            </button>
            <button wire:click="reject" wire:confirm="End this auction unsold?">
                Reject
            </button>
        </div>
    @else
        <p>{{ ucfirst($proposal->status) }} on {{ $proposal->decided_at?->format('M j, Y') }}.</p>
    @endif
</div>
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Auctions/ReserveDecisionPanelTest.php`
Expected: PASS, 3 tests, 0 failures.

- [ ] **Step 7: Wire pending-review proposals into the seller dashboard**

In `routes/web.php`, add `use App\Models\ReserveNotMetProposal;` to the
existing top-of-file import block (alongside `Auction`, `AuctionCategory`,
`Bid`, `Watchlist`). Inside the existing `/dashboard` closure, add a query
for the seller's own pending proposals and pass it to the view. Insert
after the existing `$endingSoon` query:

```php
$pendingReserveProposals = ReserveNotMetProposal::whereHas(
    'auction', fn ($q) => $q->where('seller_id', $userId)
)->where('status', 'pending')->with(['auction', 'bid.bidder'])->get();
```

Add `'pendingReserveProposals'` to the `compact(...)` call's argument list.

- [ ] **Step 8: Render the panel on the dashboard**

In `resources/views/dashboard.blade.php`, add near the top of the seller's
own content area (before the existing `$recentAuctions` table — read the
surrounding markup first to match indentation and existing card-wrapper
style used for `$endingSoon`):

```blade
@if($pendingReserveProposals->count())
    <div class="pending-reserve-proposals">
        <h3>Awaiting your decision</h3>
        @foreach($pendingReserveProposals as $proposal)
            <livewire:auctions.reserve-decision-panel :proposal="$proposal" :key="$proposal->id" />
        @endforeach
    </div>
@endif
```

- [ ] **Step 9: Manual verification**

Per this repo's own no-tinker rule, do not verify with `tinker` or a
throwaway script — the Livewire test in Step 6 and the dashboard route
test suite (Task 4) already exercise this. Skip manual verification.

- [ ] **Step 10: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 11: Commit**

```bash
git add app/Policies/ReserveNotMetProposalPolicy.php \
  app/Livewire/Auctions/ReserveDecisionPanel.php \
  resources/views/livewire/auctions/reserve-decision-panel.blade.php \
  routes/web.php resources/views/dashboard.blade.php \
  tests/Feature/Auctions/ReserveDecisionPanelTest.php
git commit -m "$(cat <<'EOF'
feat: seller reserve-not-met review panel on the dashboard

ReserveDecisionPanel (mirrors BidPanel's existing shape: mount-by-
model, inline logic, no separate Action/DTO layer) lets the auction's
own seller accept the top bid below reserve or reject it, gated by
the new ReserveNotMetProposalPolicy (reuses AuctionPolicy's existing
seller-ownership check -- no new role). Wired into the seller's own
dashboard, scoped to their own auctions only.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Full regression

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test --compact`
Expected: 0 failures.

- [ ] **Step 2: Run Pint across the whole repo**

Run: `vendor/bin/pint --format agent`
Expected: `passed` (or auto-fixes with no functional change).

- [ ] **Step 3: Report**

Report the final test count and confirm the working tree is clean
(`git status --short`). No manual tinker verification, per this repo's
own Laravel Boost guideline and the Global Constraints above — the test
suite (Tasks 1-3) already proves the settlement and review flows work.

---

## Self-Review Notes

- **Spec coverage:** §1 (migration) → Task 1 Steps 1-2. §2 (proposal table
  + model) → Task 1 Steps 2-4. §3 (settlement command) → Task 2 Steps 3-4,
  6. §4 (events) → Task 2 Step 3. §5 (policy) → Task 3 Step 3. §6
  (Livewire panel + dashboard wiring) → Task 3 Steps 4-8. Testing Strategy
  → Task 1 Step 5, Task 2 Step 1, Task 3 Step 1. All spec sections have a
  task. No gaps found.
- **Placeholder scan:** none found — every step has real, complete code.
- **Type consistency:** `AuctionSettled(Auction $auction, ?Bid $winningBid)`
  matches its dispatch sites in both the command (Task 2 Step 4) and its
  test assertions (Task 2 Step 1) exactly. `ReserveNotMetProposal`'s
  `$fillable` (Task 1 Step 4) matches every `::create()` call across Tasks
  1-3. `ReserveDecisionPanel::mount(ReserveNotMetProposal $proposal)`
  matches how Task 3 Step 7's dashboard wiring passes `:proposal="$proposal"`.
