<?php

namespace Tests\Feature\Payments;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\SettlementPayment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

class SettlementMigrationTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    /** The migrations this feature adds, newest first. */
    private function ownMigrations(): array
    {
        return [
            'database/migrations/2026_10_19_000002_create_settlement_payments_table.php',
            'database/migrations/2026_10_19_000001_add_payment_columns_to_auction_settlements_table.php',
        ];
    }

    public function test_a_legacy_awaiting_payment_row_survives_the_migration_untouched_and_without_a_deadline(): void
    {
        foreach ($this->ownMigrations() as $path) {
            Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
        }
        $this->assertFalse(Schema::hasTable('settlement_payments'));
        $this->assertFalse(Schema::hasColumn('auction_settlements', 'payment_due_at'));

        // A row written before any of this existed: no payment columns at all.
        $auction = Auction::factory()->create(['status' => 'ended']);
        $winner = User::factory()->create();
        $bid = Bid::create(['auction_id' => $auction->id, 'bidder_id' => $winner->id, 'amount' => '777.00', 'is_winning' => true]);
        $legacyId = (string) Str::uuid();
        DB::table('auction_settlements')->insert([
            'id' => $legacyId, 'auction_id' => $auction->id, 'winning_bid_id' => $bid->id,
            'seller_id' => $auction->seller_id, 'winner_id' => $winner->id,
            'amount_cents' => 77700, 'currency' => 'ZAR', 'status' => 'awaiting_payment',
            'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30),
        ]);

        foreach (array_reverse($this->ownMigrations()) as $path) {
            Artisan::call('migrate', ['--path' => $path, '--force' => true]);
        }

        $row = AuctionSettlement::findOrFail($legacyId);
        $this->assertSame(AuctionSettlement::AWAITING_PAYMENT, $row->status);
        $this->assertSame(77700, $row->amount_cents);
        $this->assertSame('ZAR', $row->currency);
        $this->assertNull($row->payment_due_at, 'a legacy row gets no deadline until payment opens');
        $this->assertNull($row->paid_at);
        $this->assertTrue(Schema::hasTable('settlement_payments'));
    }

    public function test_the_unique_indexes_the_flow_depends_on_exist(): void
    {
        $this->attempt($this->settlement()[0], ['reference' => 'AUC-DUP-1', 'provider_transaction_id' => 'T1']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->attempt($this->settlement()[0], ['reference' => 'AUC-DUP-2', 'provider_transaction_id' => 'T1']);
    }

    public function test_many_rows_may_have_no_provider_transaction_id_yet(): void
    {
        $this->attempt($this->settlement()[0], ['reference' => 'AUC-NULL-1']);
        $this->attempt($this->settlement()[0], ['reference' => 'AUC-NULL-2']);

        $this->assertSame(2, SettlementPayment::whereNull('provider_transaction_id')->count());
    }

    public function test_a_reference_is_unique(): void
    {
        $this->attempt($this->settlement()[0], ['reference' => 'AUC-SAME']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->attempt($this->settlement()[0], ['reference' => 'AUC-SAME']);
    }
}
