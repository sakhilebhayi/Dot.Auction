<?php

namespace Tests\Feature\R42;

use App\Models\AuctionSettlement;
use App\Support\SettlementPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

/**
 * Design jury 1, P0 1: the dashboard said "overdue / you owe / Pay now" while the sale page said "Nothing is due yet" with no button.
 * One answer (App\Support\SettlementPosition) now drives every page. Each recorded settlement state x payment on/off x page is pinned here:
 * "Pay now" appears only where a working button exists, "overdue" only for a sale that can be paid, and while payment is closed every page says
 * the same honest thing.
 */
class SettlementOneAnswerTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    /** recorded status, payment on, expected position */
    public static function states(): array
    {
        return [
            'awaiting, payment closed' => ['awaiting_payment', false, SettlementPosition::WAITING],
            'overdue row, payment closed' => ['payment_overdue', false, SettlementPosition::WAITING],
            'awaiting, payment open' => ['awaiting_payment', true, SettlementPosition::DUE],
            'overdue, payment open' => ['payment_overdue', true, SettlementPosition::OVERDUE],
            'paid' => ['paid', true, SettlementPosition::PAID],
            'paid, payment closed later' => ['paid', false, SettlementPosition::PAID],
            'cancelled' => ['cancelled', true, SettlementPosition::CANCELLED],
        ];
    }

    #[DataProvider('states')]
    public function test_the_position_matches_the_recorded_state_and_the_payment_switch(string $status, bool $on, string $expected): void
    {
        $on ? $this->enablePaystack() : $this->disablePaystack();
        [$settlement] = $this->settlement(590000, ['status' => $status]);

        $position = SettlementPosition::of($settlement);

        $this->assertSame($expected, $position->state());
        $this->assertSame(in_array($expected, [SettlementPosition::DUE, SettlementPosition::OVERDUE], true), $position->canPayNow());
        $this->assertSame($expected === SettlementPosition::OVERDUE, $position->isOverdue());
        $this->assertSame($expected === SettlementPosition::OVERDUE, $position->sellerCanCancel());
        $this->assertSame($expected === SettlementPosition::WAITING ? 590000 : 0, $position->owedLaterCents());
        $this->assertSame($position->canPayNow() ? 590000 : 0, $position->owedNowCents());
        $this->assertNotSame('payment_overdue', $expected === SettlementPosition::WAITING ? $position->badge() : 'x');
    }

    public function test_totals_split_what_is_payable_now_from_what_will_be_owed(): void
    {
        $this->enablePaystack();
        [$a, $winner] = $this->settlement(100000, ['status' => 'awaiting_payment']);
        [$b] = $this->settlement(250000, ['status' => 'payment_overdue'], $winner);
        [$c] = $this->settlement(900000, ['status' => 'paid'], $winner);

        $open = SettlementPosition::totals(SettlementPosition::many([$a, $b, $c]));
        $this->assertSame(['now_cents' => 350000, 'now_count' => 2, 'overdue_count' => 1, 'later_cents' => 0, 'later_count' => 0], $open);

        $this->disablePaystack();
        $closed = SettlementPosition::totals(SettlementPosition::many([$a, $b, $c]));
        $this->assertSame(['now_cents' => 0, 'now_count' => 0, 'overdue_count' => 0, 'later_cents' => 350000, 'later_count' => 2], $closed);
    }

    /** page => route builder */
    public static function pages(): array
    {
        return ['home' => ['dashboard'], 'won' => ['buyer.won'], 'my bids' => ['buyer.bids'], 'payments' => ['settlements.index'], 'sale' => ['settlements.show'], 'lot' => ['auctions.show']];
    }

    private function visit(string $page, AuctionSettlement $s, $user): string
    {
        $url = match ($page) {
            'settlements.show' => route('settlements.show', $s),
            'auctions.show' => route('auctions.show', $s->auction_id),
            default => route($page),
        };
        $response = $this->actingAs($user)->get($url)->assertOk();

        return $this->mainText($response->getContent());
    }

    /** The visible text of <main> only: no head, no scripts, no shell. Whitespace collapsed. */
    private function mainText(string $html): string
    {
        preg_match('~<main\b.*?</main>~s', $html, $m);
        $main = preg_replace('~<(script|style)\b.*?</\1>~s', '', $m[0] ?? $html);

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($main))));
    }

    private function seen(string $text, string $needle): bool
    {
        return mb_stripos($text, $needle) !== false;
    }

    #[DataProvider('pages')]
    public function test_while_payment_is_closed_every_page_tells_the_same_honest_story(string $page): void
    {
        $this->disablePaystack();

        foreach (['awaiting_payment', 'payment_overdue'] as $status) {
            [$settlement, $winner] = $this->settlement(590000, ['status' => $status, 'payment_due_at' => now()->subDay()]);
            $text = $this->visit($page, $settlement, $winner);

            $this->assertFalse($this->seen($text, 'Pay now'), "$page, $status: no button without a working payment ... $text");
            $this->assertFalse($this->seen($text, 'overdue'), "$page, $status: nothing can be late while payment is closed");
            $this->assertFalse($this->seen($text, 'You owe'), "$page, $status: nothing is owed today");
            $this->assertFalse($this->seen($text, 'Nothing is due yet'));
            $this->assertFalse($this->seen($text, 'Pay by'));
            $this->assertFalse($this->seen($text, 'Was due'));

            if ($page !== 'auctions.show') {
                $this->assertTrue($this->seen($text, 'opens soon') || $this->seen($text, 'will owe'), "$page, $status: says payment opens soon");
            }
            if (in_array($page, ['dashboard', 'buyer.won', 'settlements.show'], true)) {
                $this->assertTrue($this->seen($text, 'R5,900.00'), "$page: names what will be owed");
            }
        }
    }

    #[DataProvider('pages')]
    public function test_while_payment_is_open_the_pay_now_path_exists_on_every_page_that_asks(string $page): void
    {
        $this->enablePaystack();

        [$settlement, $winner] = $this->settlement(590000, ['status' => 'payment_overdue', 'payment_due_at' => now()->subDay()]);
        $text = $this->visit($page, $settlement, $winner);

        $this->assertTrue($this->seen($text, 'Pay now'), "$page: a winner with a payable sale is offered Pay now");
        if ($page !== 'auctions.show') {
            $this->assertTrue($this->seen($text, 'overdue'), "$page: late is said once it is true");
        }
    }

    #[DataProvider('pages')]
    public function test_paid_and_cancelled_sales_ask_for_nothing(string $page): void
    {
        foreach (['paid', 'cancelled'] as $status) {
            $this->enablePaystack();
            [$settlement, $winner] = $this->settlement(590000, ['status' => $status, 'paid_at' => $status === 'paid' ? now() : null, 'cancelled_at' => $status === 'cancelled' ? now() : null]);
            $text = $this->visit($page, $settlement, $winner);

            $this->assertFalse($this->seen($text, 'Pay now'), "$page, $status");
            // The sale page may say why a cancelled sale was cancelled ("because payment was overdue"); that is history, not a demand.
            if (! ($page === 'settlements.show' && $status === 'cancelled')) {
                $this->assertFalse($this->seen($text, 'overdue'), "$page, $status");
            }
            $this->assertFalse($this->seen($text, 'You owe'), "$page, $status");
        }
    }

    public function test_the_sellers_cancel_button_needs_a_sale_that_could_have_been_paid(): void
    {
        $this->disablePaystack();
        [$settlement, , $seller] = $this->settlement(590000, ['status' => 'payment_overdue']);
        $text = $this->visit('settlements.show', $settlement, $seller);
        $this->assertFalse($this->seen($text, 'Cancel this sale'));
        $this->assertFalse($this->seen($text, 'has not paid on time'));

        $this->enablePaystack();
        $text = $this->visit('settlements.show', $settlement, $seller);
        $this->assertTrue($this->seen($text, 'Cancel this sale'));
    }

    public function test_home_names_what_will_be_owed_without_a_button_while_payment_is_closed(): void
    {
        $this->disablePaystack();
        [$settlement, $winner] = $this->settlement(370000, ['status' => 'awaiting_payment']);

        $html = $this->actingAs($winner)->get(route('dashboard'))->assertOk()->getContent();
        $text = html_entity_decode(strip_tags($html));

        $this->assertTrue($this->seen($text, 'Waiting for payment to open'));
        $this->assertTrue($this->seen($text, 'You will owe R3,700.00 once it does'));
        // The needs strip has no action link for this line.
        $this->assertDoesNotMatchRegularExpression('/dc-need__action/', $html);
    }

    public function test_the_sale_history_does_not_claim_payment_opened_or_a_deadline_passed_while_payment_is_closed(): void
    {
        $this->disablePaystack();
        [$settlement, $winner] = $this->settlement(370000, ['status' => 'payment_overdue']);
        $settlement->events()->create(['type' => 'payment_opened', 'note' => 'x']);
        $settlement->events()->create(['type' => 'overdue', 'note' => 'x']);

        $text = $this->visit('settlements.show', $settlement, $winner);
        $this->assertFalse($this->seen($text, 'Payment opened for the buyer'));
        $this->assertFalse($this->seen($text, 'marked overdue'));
    }
}
