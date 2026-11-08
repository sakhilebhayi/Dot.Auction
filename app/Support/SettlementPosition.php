<?php

namespace App\Support;

use App\Models\Auction;
use App\Models\AuctionSettlement;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Support\Collection;

/**
 * The one answer to "can this sale be paid now, is it late, and what is owed?", for every page that has to say so
 * (Home, Won, My bids, Payments, the sale page, the lot page). A settlement row alone cannot answer: its status says
 * what was recorded, not whether the winner has anywhere to pay. Payment can be switched off (no Paystack key, or a
 * refused one), and then nothing is payable and nothing can be late, whatever an old row says.
 *
 * Five states, in the order a sale moves through them:
 *
 *   waiting    the sale is unpaid but online payment is not open: nothing is due today, an amount will be
 *   due        unpaid and payable now (awaiting payment, inside the deadline)
 *   overdue    unpaid, payable now, past the deadline (the seller may cancel it)
 *   paid       final
 *   cancelled  final
 *
 * No rule lives here that the lifecycle and payer do not already enforce; this only reads them (isPayable() and
 * PaystackConfig::canTakePayments()) so the wording and the buttons cannot disagree.
 */
final class SettlementPosition
{
    public const WAITING = 'waiting';

    public const DUE = 'due';

    public const OVERDUE = 'overdue';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    private function __construct(public readonly AuctionSettlement $settlement, private readonly bool $paymentOpen) {}

    public static function of(AuctionSettlement $settlement, ?PaystackConfig $config = null): self
    {
        return new self($settlement, ($config ?? app(PaystackConfig::class))->canTakePayments());
    }

    /**
     * @param  iterable<AuctionSettlement>  $settlements
     * @return Collection<int, self>
     */
    public static function many(iterable $settlements, ?PaystackConfig $config = null): Collection
    {
        $open = ($config ?? app(PaystackConfig::class))->canTakePayments();

        return collect($settlements)->map(fn (AuctionSettlement $s) => new self($s, $open))->values();
    }

    public function state(): string
    {
        return match (true) {
            $this->settlement->isPaid() => self::PAID,
            $this->settlement->status === AuctionSettlement::CANCELLED => self::CANCELLED,
            ! $this->paymentOpen => self::WAITING,
            $this->settlement->status === AuctionSettlement::PAYMENT_OVERDUE => self::OVERDUE,
            default => self::DUE,
        };
    }

    public function paymentOpen(): bool
    {
        return $this->paymentOpen;
    }

    /** The winner can press Pay now and it will work. */
    public function canPayNow(): bool
    {
        return in_array($this->state(), [self::DUE, self::OVERDUE], true);
    }

    /** Past the deadline AND payable. Never true while payment is closed. */
    public function isOverdue(): bool
    {
        return $this->state() === self::OVERDUE;
    }

    /** Unpaid, but there is nowhere to pay yet. */
    public function isWaiting(): bool
    {
        return $this->state() === self::WAITING;
    }

    /** Still money to come in, now or once payment opens. */
    public function isUnpaid(): bool
    {
        return in_array($this->state(), [self::WAITING, self::DUE, self::OVERDUE], true);
    }

    /** The seller may cancel (the lifecycle allows it only after the deadline, and only while the buyer could have paid). */
    public function sellerCanCancel(): bool
    {
        return $this->isOverdue();
    }

    /** Whether to print "Pay by" / "Was due": only when the winner can actually pay and a deadline exists. */
    public function showsDeadline(): bool
    {
        return $this->canPayNow() && $this->settlement->payment_due_at !== null;
    }

    /** The key into Display::statusVocabulary() for the badge: never "overdue" for a sale that cannot be paid. */
    public function badge(): string
    {
        return match ($this->state()) {
            self::PAID => 'paid',
            self::CANCELLED => 'cancelled',
            self::OVERDUE => 'payment_overdue',
            default => 'awaiting_payment',
        };
    }

    /**
     * The short phrase for a list row about this sale. $isWinner: true for the buyer, false for the seller, null for a third
     * party (the operator). It never says overdue or due while there is nowhere to pay, so a list cannot disagree with the sale page.
     */
    public function listLabel(?bool $isWinner): string
    {
        return match ($this->state()) {
            self::PAID => 'Paid',
            self::CANCELLED => 'Sale cancelled',
            self::OVERDUE => $isWinner === false ? 'Buyer is late paying' : 'Payment overdue',
            self::DUE => 'Awaiting payment',
            default => $isWinner === true ? 'Payment opens soon' : 'Waiting for payment to open',
        };
    }

    /** Cents owed that the winner can pay today. */
    public function owedNowCents(): int
    {
        return $this->canPayNow() ? $this->settlement->amount_cents : 0;
    }

    /** Cents owed once payment opens. */
    public function owedLaterCents(): int
    {
        return $this->isWaiting() ? $this->settlement->amount_cents : 0;
    }

    /** The label on the primary action for the winner, or null when there is nothing to press. */
    public function winnerAction(): ?string
    {
        return $this->canPayNow() ? 'Pay now' : null;
    }

    /** The label above the amount on the sale page. */
    public function amountLabel(bool $isWinner): string
    {
        if (! $isWinner) {
            return 'Sold for';
        }

        return match ($this->state()) {
            self::PAID => 'You paid',
            self::CANCELLED => 'Sale price',
            self::WAITING => 'You will owe',
            default => 'You owe',
        };
    }

    public function headline(bool $isWinner): string
    {
        return $isWinner
            ? match ($this->state()) {
                self::PAID => 'Payment received. Thank you.',
                self::CANCELLED => 'Cancelled by the seller.',
                self::OVERDUE => 'Payment is overdue.',
                self::DUE => 'Pay to complete your purchase.',
                default => 'Payment opens soon.',
            }
        : match ($this->state()) {
            self::PAID => 'The buyer has paid.',
            self::CANCELLED => 'You cancelled this sale.',
            self::OVERDUE => 'The buyer has not paid on time.',
            self::DUE => 'Waiting for the buyer to pay.',
            default => 'Waiting for payment to open.',
        };
    }

    public function detail(bool $isWinner, bool $testMode = false): ?string
    {
        $amount = $this->settlement->amount();

        return $isWinner
            ? match ($this->state()) {
                self::PAID => 'The seller has been told.'.($testMode ? ' This was a test payment: no real money moved.' : ''),
                self::CANCELLED => 'The seller cancelled this sale because payment was overdue. Nothing is due and it can no longer be paid.',
                self::OVERDUE => 'You can still pay until the seller cancels the sale.',
                self::DUE => null,
                default => "Payment is not available yet, so nothing is due today. You will owe {$amount} once it opens, and we will tell you.",
            }
        : match ($this->state()) {
            self::PAID => null,
            self::CANCELLED => 'The buyer has been told and can no longer pay. The auction has not been relisted.',
            self::OVERDUE => 'They can still pay until you cancel the sale. Dot.Auction does not relist or cancel anything for you.',
            self::DUE => 'If they do not pay by the deadline the sale is marked overdue and you decide what to do.',
            default => 'Payment is not available yet, so the sale is recorded as awaiting payment. The buyer is told when it opens.',
        };
    }

    /**
     * One sentence for a list row or a Home line about a single sale, for the winner. Names the lot and the amount.
     */
    public function winnerLine(string $lotTitle): string
    {
        $amount = $this->settlement->amount();

        return match ($this->state()) {
            self::OVERDUE => "Payment is overdue on {$lotTitle} ({$amount}). You can still pay until the seller cancels the sale.",
            self::DUE => "You won {$lotTitle}. Pay {$amount}".($this->settlement->payment_due_at ? ' by '.Display::time($this->settlement->payment_due_at, 'D j M, H:i', true) : '').'.',
            self::WAITING => "Payment for {$lotTitle} opens soon. You will owe {$amount} once it does.",
            default => '',
        };
    }

    /**
     * Totals over a set of the winner's sales: how many and how much can be paid now (and how many of those are late),
     * and how many and how much will be owed once payment opens. Paid and cancelled sales count for nothing.
     *
     * @param  iterable<self>  $positions
     * @return array{now_cents: int, now_count: int, overdue_count: int, later_cents: int, later_count: int}
     */
    public static function totals(iterable $positions): array
    {
        $t = ['now_cents' => 0, 'now_count' => 0, 'overdue_count' => 0, 'later_cents' => 0, 'later_count' => 0];

        foreach ($positions as $p) {
            if ($p->canPayNow()) {
                $t['now_cents'] += $p->settlement->amount_cents;
                $t['now_count']++;
                $t['overdue_count'] += $p->isOverdue() ? 1 : 0;
            } elseif ($p->isWaiting()) {
                $t['later_cents'] += $p->settlement->amount_cents;
                $t['later_count']++;
            }
        }

        return $t;
    }

    public static function money(int $cents): string
    {
        return Auction::money($cents / 100);
    }
}
