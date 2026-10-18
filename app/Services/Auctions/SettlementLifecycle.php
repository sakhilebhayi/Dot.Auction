<?php

namespace App\Services\Auctions;

use App\Models\AuctionSettlement;
use App\Models\SettlementEvent;
use App\Models\SettlementPayment;
use App\Models\User;
use App\Notifications\SettlementCancelledNotification;
use App\Notifications\SettlementOverdueNotification;
use App\Notifications\SettlementPaymentOpenedNotification;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Support\Facades\DB;

/**
 * The payment deadline and the seller's decision after it.
 *
 * Nothing here relists, cancels or changes the auction: an overdue sale is a human decision. The
 * scheduled command only (1) starts the clock for sales recorded before online payment was switched
 * on, and (2) marks unpaid sales past their deadline as overdue. A sale that is already paid or
 * cancelled is never touched, every change re-reads the row under a lock, and re-running changes and
 * notifies nothing twice. With payment switched off none of it runs: a winner who cannot pay cannot
 * be late.
 */
class SettlementLifecycle
{
    public function __construct(private readonly PaystackConfig $config) {}

    /**
     * @return int how many settlements were moved to payment_overdue
     */
    public function markOverdue(): int
    {
        if (! $this->config->canTakePayments()) {
            return 0;
        }

        $ids = AuctionSettlement::query()
            ->where('status', AuctionSettlement::AWAITING_PAYMENT)
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<=', now())
            ->pluck('id');

        $moved = 0;

        foreach ($ids as $id) {
            $moved += DB::transaction(function () use ($id): int {
                $settlement = AuctionSettlement::query()->lockForUpdate()->find($id);

                if ($settlement === null
                    || $settlement->status !== AuctionSettlement::AWAITING_PAYMENT
                    || $settlement->payment_due_at === null
                    || $settlement->payment_due_at->isFuture()) {
                    return 0;
                }

                $settlement->update(['status' => AuctionSettlement::PAYMENT_OVERDUE, 'overdue_at' => now()]);

                SettlementEvent::create([
                    'settlement_id' => $settlement->id,
                    'type' => 'overdue',
                    'note' => 'Payment deadline passed ('.$settlement->payment_due_at->toIso8601String().').',
                ]);

                DB::afterCommit(function () use ($settlement) {
                    $settlement->loadMissing(['auction', 'winner', 'seller']);
                    $settlement->winner?->notify(new SettlementOverdueNotification($settlement, 'winner'));
                    $settlement->seller?->notify(new SettlementOverdueNotification($settlement, 'seller'));
                });

                return 1;
            });
        }

        return $moved;
    }

    /**
     * Give a deadline to awaiting sales that have none (recorded before payment was switched on),
     * counted from now, and tell the winner payment is open.
     *
     * @return int how many clocks were started
     */
    public function openClocks(): int
    {
        if (! $this->config->canTakePayments()) {
            return 0;
        }

        $ids = AuctionSettlement::query()
            ->where('status', AuctionSettlement::AWAITING_PAYMENT)
            ->whereNull('payment_due_at')
            ->pluck('id');

        $started = 0;

        foreach ($ids as $id) {
            $started += DB::transaction(function () use ($id): int {
                $settlement = AuctionSettlement::query()->lockForUpdate()->find($id);

                if ($settlement === null || $settlement->status !== AuctionSettlement::AWAITING_PAYMENT || $settlement->payment_due_at !== null) {
                    return 0;
                }

                $settlement->update(['payment_due_at' => now()->addHours($this->config->paymentWindowHours())]);

                SettlementEvent::create([
                    'settlement_id' => $settlement->id,
                    'type' => 'payment_opened',
                    'note' => 'Payment opened; deadline '.$settlement->payment_due_at->toIso8601String().'.',
                ]);

                DB::afterCommit(function () use ($settlement) {
                    $settlement->loadMissing(['auction', 'winner']);
                    $settlement->winner?->notify(new SettlementPaymentOpenedNotification($settlement));
                });

                return 1;
            });
        }

        return $started;
    }

    /**
     * The seller gives up on an overdue sale. Audited, and the winner is told. The auction itself is
     * left exactly as it is: no relist, no change of status.
     *
     * @throws SettlementActionRejected
     */
    public function cancel(AuctionSettlement|string $settlement, User $seller): AuctionSettlement
    {
        $id = $settlement instanceof AuctionSettlement ? $settlement->getKey() : $settlement;

        return DB::transaction(function () use ($id, $seller) {
            $locked = AuctionSettlement::query()->lockForUpdate()->find($id);

            if ($locked === null || ! $locked->isSeller($seller)) {
                throw new SettlementActionRejected('Only the seller can cancel this sale.');
            }

            if ($locked->status === AuctionSettlement::CANCELLED) {
                throw new SettlementActionRejected('This sale is already cancelled.');
            }

            if ($locked->isPaid()) {
                throw new SettlementActionRejected('This sale has been paid, so it cannot be cancelled.');
            }

            if ($locked->status !== AuctionSettlement::PAYMENT_OVERDUE) {
                throw new SettlementActionRejected('You can cancel a sale only once its payment is overdue.');
            }

            $locked->update([
                'status' => AuctionSettlement::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $seller->getKey(),
            ]);

            // Close any checkout still open so it cannot be used after the cancel.
            SettlementPayment::query()
                ->where('settlement_id', $locked->id)
                ->where('status', SettlementPayment::INITIATED)
                ->update(['status' => SettlementPayment::EXPIRED]);

            SettlementEvent::create([
                'settlement_id' => $locked->id,
                'user_id' => $seller->getKey(),
                'type' => 'cancelled',
                'note' => 'Cancelled by the seller after the payment deadline.',
            ]);

            DB::afterCommit(function () use ($locked) {
                $locked->loadMissing(['auction', 'winner']);
                $locked->winner?->notify(new SettlementCancelledNotification($locked));
            });

            return $locked;
        });
    }
}
