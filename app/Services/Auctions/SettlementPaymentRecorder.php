<?php

namespace App\Services\Auctions;

use App\Models\AuctionSettlement;
use App\Models\SettlementEvent;
use App\Models\SettlementPayment;
use App\Notifications\SettlementPaidNotification;
use App\Services\Paystack\VerifiedTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The single place a settlement becomes paid. Both the winner's return from Paystack (after a server
 * to server verify) and the signed webhook end here, so there is one set of rules.
 *
 * A settlement is marked paid only when ALL of these hold, judged on the locked rows:
 * the reference is one this app issued; Paystack says `success`; the amount is a whole number of cents
 * equal to the SETTLEMENT's amount (and the attempt's); the currency matches; any settlement id in
 * the metadata matches; the Paystack transaction id has not been used by another payment; and the
 * settlement is still payable. Anything else is held: the attempt becomes `unmatched` with a reason
 * and the settlement is left exactly as it was, for an operator to look at.
 *
 * Exactly once: the settlement row is locked first, then the attempt row, in that order everywhere;
 * unique(reference) and unique(provider, provider_transaction_id) are the backstop. Replays, a
 * callback racing a webhook, and a stale copy of the row all land on the same answer.
 */
class SettlementPaymentRecorder
{
    public const FROM_CALLBACK = 'callback';

    public const FROM_WEBHOOK = 'webhook';

    public const PAID = 'paid';

    public const ALREADY_PAID = 'already_paid';

    public const FAILED = 'failed';

    public const PENDING = 'pending';

    public const HELD = 'held';

    /**
     * @param  array<string, mixed>|null  $metadata  the metadata Paystack echoed, when it sent any
     * @param  SettlementPayment|null  $attempt  the attempt we asked Paystack about (callback); the webhook finds its attempt by reference
     * @return string one of the outcome constants
     */
    public function apply(VerifiedTransaction $tx, string $source, ?array $metadata = null, ?SettlementPayment $attempt = null): string
    {
        $found = $attempt ?? SettlementPayment::query()
            ->where('provider', 'paystack')
            ->where('reference', $tx->reference)
            ->first();

        if ($found === null) {
            $this->holdUnknown($tx);

            return self::HELD;
        }

        if ($found->settlement_id === null) {
            return self::HELD;
        }

        return DB::transaction(function () use ($found, $tx, $source, $metadata) {
            // Always settlement first, then the attempt.
            $settlement = AuctionSettlement::query()->lockForUpdate()->find($found->settlement_id);
            $payment = SettlementPayment::query()->lockForUpdate()->find($found->id);

            if ($settlement === null || $payment === null) {
                return self::HELD;
            }

            if ($payment->status === SettlementPayment::PAID) {
                return self::ALREADY_PAID;
            }

            if ($payment->status === SettlementPayment::UNMATCHED) {
                return self::HELD;
            }

            if (! $tx->isSuccessful() && $source === self::FROM_CALLBACK) {
                return $this->recordNonSuccess($payment, $tx);
            }

            $reason = $this->mismatch($tx, $settlement, $payment, $metadata);

            if ($reason !== null) {
                return $this->hold($payment, $reason, $this->describe($tx, $settlement));
            }

            if ($settlement->status === AuctionSettlement::PAID) {
                return $this->hold($payment, 'duplicate_payment', 'This sale was already paid by another payment. This one needs a refund decision.');
            }

            if ($settlement->status === AuctionSettlement::CANCELLED) {
                return $this->hold($payment, 'settlement_cancelled', 'Money arrived for a sale the seller had cancelled. It needs a refund decision.');
            }

            $paidAt = $tx->paidAtOrNow();

            $payment->update([
                'status' => SettlementPayment::PAID,
                'reason' => null,
                'provider_transaction_id' => $tx->transactionId,
                'paid_at' => $paidAt,
                'summary' => 'Confirmed by '.($source === self::FROM_WEBHOOK ? 'Paystack webhook' : 'server-side verification').'.',
            ]);

            $settlement->update(['status' => AuctionSettlement::PAID, 'paid_at' => $paidAt]);

            SettlementEvent::create([
                'settlement_id' => $settlement->id,
                'user_id' => null,
                'type' => 'paid',
                'note' => 'Payment '.$payment->reference.' confirmed by '.$source.'.',
            ]);

            DB::afterCommit(function () use ($settlement) {
                $settlement->loadMissing(['auction', 'winner', 'seller']);
                $settlement->winner?->notify(new SettlementPaidNotification($settlement, 'winner'));
                $settlement->seller?->notify(new SettlementPaidNotification($settlement, 'seller'));
            });

            return self::PAID;
        });
    }

    private function mismatch(VerifiedTransaction $tx, AuctionSettlement $settlement, SettlementPayment $payment, ?array $metadata): ?string
    {
        if (! $tx->isSuccessful()) {
            return 'not_successful';
        }

        if ($tx->reference !== $payment->reference) {
            return 'reference_mismatch';
        }

        if ($tx->amountCents === null) {
            return 'malformed_amount';
        }

        if ($tx->currency !== $settlement->currency || $tx->currency !== $payment->currency) {
            return 'currency_mismatch';
        }

        if ($tx->amountCents !== $settlement->amount_cents || $tx->amountCents !== $payment->amount_cents) {
            return 'amount_mismatch';
        }

        if ($metadata !== null && isset($metadata['settlement_id']) && (string) $metadata['settlement_id'] !== (string) $settlement->id) {
            return 'metadata_mismatch';
        }

        if ($tx->transactionId === null) {
            return 'missing_transaction_id';
        }

        $used = SettlementPayment::query()
            ->where('provider', 'paystack')
            ->where('provider_transaction_id', $tx->transactionId)
            ->where('id', '!=', $payment->id)
            ->exists();

        return $used ? 'duplicate_transaction' : null;
    }

    private function recordNonSuccess(SettlementPayment $payment, VerifiedTransaction $tx): string
    {
        if (in_array($tx->status, ['failed', 'abandoned', 'reversed'], true)) {
            $payment->update(['status' => SettlementPayment::FAILED, 'summary' => 'Paystack reported "'.$tx->status.'".']);

            return self::FAILED;
        }

        return self::PENDING;
    }

    private function hold(SettlementPayment $payment, string $reason, string $summary): string
    {
        $payment->update(['status' => SettlementPayment::UNMATCHED, 'reason' => $reason, 'summary' => $summary]);

        return self::HELD;
    }

    private function describe(VerifiedTransaction $tx, AuctionSettlement $settlement): string
    {
        return 'Paystack reported status "'.$tx->status.'", '.($tx->amountCents ?? 'an unreadable amount').' '.($tx->currency ?: '?')
            .' (cents); the sale is '.$settlement->amount_cents.' '.$settlement->currency.'.';
    }

    /**
     * A success for a reference this app never issued (another integration on the same Paystack
     * account, or something forged by a party holding the key). Kept for an operator, once.
     */
    private function holdUnknown(VerifiedTransaction $tx): void
    {
        $idTaken = $tx->transactionId !== null && SettlementPayment::query()
            ->where('provider', 'paystack')->where('provider_transaction_id', $tx->transactionId)->exists();

        try {
            SettlementPayment::query()->firstOrCreate(
                ['reference' => $tx->reference],
                [
                    'provider' => 'paystack',
                    'settlement_id' => null,
                    'amount_cents' => $tx->amountCents,
                    'currency' => $tx->currency !== '' ? substr($tx->currency, 0, 3) : null,
                    'status' => SettlementPayment::UNMATCHED,
                    'reason' => 'unknown_reference',
                    'provider_transaction_id' => $idTaken ? null : $tx->transactionId,
                    'summary' => 'Paystack reported "'.$tx->status.'" for a reference this app did not issue.',
                ],
            );
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery of the same event stored it first.
        }
    }
}
