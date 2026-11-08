<?php

namespace Tests\Feature\Security;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Models\Bid;
use App\Models\SettlementPayment;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * What each model lets a bag of request data set. Pinned, so widening a $fillable is a visible decision, and the columns that
 * decide money, status, ownership or rights are never among them.
 */
class MassAssignmentTest extends TestCase
{
    /** Columns no model may ever allow to be mass assigned. */
    private const NEVER = [
        'id', 'seller_id', 'winner_id', 'bidder_id', 'status', 'current_price', 'is_winning', 'is_platform_operator', 'amount_cents', 'paid_at',
        'snipe_extensions', 'winning_bid_id', 'reference', 'infodot_team_uuid', 'current_team_id', 'remember_token', 'two_factor_secret', 'email_verified_at',
    ];

    public function test_the_fillable_columns_of_the_models_a_person_can_reach_are_exactly_these(): void
    {
        $this->assertSame(['category_id', 'title', 'description', 'starting_price', 'reserve_price', 'buy_now_price', 'bid_increment', 'starts_at', 'ends_at'], (new Auction)->getFillable());
        $this->assertSame(['name', 'email', 'password'], (new User)->getFillable());
        $this->assertSame(['name', 'personal_team'], (new Team)->getFillable());
        $this->assertSame(['email', 'role'], (new TeamInvitation)->getFillable());
        $this->assertSame(['user_id', 'auction_id'], (new Watchlist)->getFillable());
        $this->assertSame([], (new Bid)->getFillable());
        $this->assertSame([], (new AuctionSettlement)->getFillable());
        $this->assertSame([], (new SettlementPayment)->getFillable());
    }

    public function test_the_money_and_rights_columns_are_not_fillable_on_any_model(): void
    {
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            $fillable = (new $class)->getFillable();

            // A few server-only models list their foreign keys and statuses because only services create them; the
            // user-reachable ones are pinned above. This catches the dangerous columns on the others.
            $allowedServerOnly = [
                'App\\Models\\ReserveNotMetProposal' => ['status'],
                'App\\Models\\AuctionImage' => [],
                'App\\Models\\SettlementEvent' => [],
            ];
            foreach (array_intersect($fillable, self::NEVER) as $column) {
                if ($column === 'id' || in_array($column, $allowedServerOnly[$class] ?? [], true)) {
                    continue;
                }
                $this->fail("{$class} allows mass assignment of '{$column}'");
            }
        }
        $this->addToAssertionCount(1);
    }

    /** Fill a model with hostile attributes: Laravel either throws (strict mode) or silently drops them; neither may set them. */
    private function hostileFill(Model $model, array $attributes): Model
    {
        foreach ($attributes as $key => $value) {
            try {
                $model->fill([$key => $value]);
            } catch (MassAssignmentException) {
                // refused loudly: exactly what strict mode is for
            }
        }

        return $model;
    }

    public function test_a_bag_of_hostile_attributes_sets_nothing_it_should_not(): void
    {
        $user = $this->hostileFill(new User, ['is_platform_operator' => true, 'current_team_id' => 99, 'email_verified_at' => now(), 'two_factor_secret' => 'x', 'remember_token' => 'x']);
        $this->assertSame([], $user->getAttributes());

        $auction = $this->hostileFill(new Auction, ['seller_id' => 5, 'status' => 'ended', 'current_price' => '1.00', 'snipe_extensions' => 9]);
        $this->assertSame([], $auction->getAttributes());

        $bid = $this->hostileFill(new Bid, ['amount' => '1.00', 'bidder_id' => 5, 'is_winning' => true, 'auction_id' => 1]);
        $this->assertSame([], $bid->getAttributes());

        $team = $this->hostileFill(new Team, ['user_id' => 5, 'infodot_team_uuid' => 'abc']);
        $this->assertSame([], $team->getAttributes());

        $sale = $this->hostileFill(new AuctionSettlement, ['status' => 'paid', 'amount_cents' => 1, 'winner_id' => 5, 'paid_at' => now()]);
        $this->assertSame([], $sale->getAttributes());
    }

    public function test_no_request_path_hands_raw_input_to_a_model(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/\$request->all\(\)|\$request->input\(\)|Model::unguard\(|->fill\(\$request|::create\(\$request|->update\(\$request|forceFill\(\$request/', $code)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders);
    }
}
