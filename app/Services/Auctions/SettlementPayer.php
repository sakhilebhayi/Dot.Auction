<?php

namespace App\Services\Auctions;

use App\Models\AuctionSettlement;
use App\Models\SettlementPayment;
use App\Models\User;
use App\Services\Paystack\PaymentGatewayException;
use App\Services\Paystack\PaystackClient;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The winner's side of paying: start a Paystack payment, and confirm one on return.
 *
 * start(): inside one transaction with the settlement row locked, so two clicks (or two tabs) cannot
 * open two payments. A live, unexpired attempt is reused; expired ones are retired; otherwise a new
 * one is initialized with Paystack. The amount and currency come from the settlement row, never from
 * the request. If Paystack cannot be reached the transaction rolls back and nothing is left behind,
 * so the winner can simply try again.
 *
 * confirm(): asks Paystack (server to server) about the winner's most recent attempt; query
 * parameters on the return URL are never read. The decision is made by SettlementPaymentRecorder.
 */
class SettlementPayer
{
    public function __construct(
        private readonly PaystackConfig $config,
        private readonly PaystackClient $client,
        private readonly SettlementPaymentRecorder $recorder,
    ) {}

    /**
     * @throws SettlementActionRejected when the settlement cannot be paid right now
     * @throws PaymentGatewayException when Paystack could not be used (nothing was changed)
     */
    public function start(AuctionSettlement|string $settlement, User $user): SettlementPayment
    {
        if (! $this->config->canTakePayments()) {
            throw new SettlementActionRejected('Online payment is not available yet, so nothing is due now.');
        }

        $id = $settlement instanceof AuctionSettlement ? $settlement->getKey() : $settlement;

        return DB::transaction(function () use ($id, $user) {
            $locked = AuctionSettlement::query()->lockForUpdate()->find($id);

            if ($locked === null || ! $locked->isWinner($user)) {
                throw new SettlementActionRejected('Only the winner can pay for this sale.');
            }

            if ($locked->isPaid()) {
                throw new SettlementActionRejected('This sale is already paid. Nothing more is due.');
            }

            if (! $locked->isPayable()) {
                throw new SettlementActionRejected('This sale was cancelled by the seller. Nothing is due.');
            }

            SettlementPayment::query()
                ->where('settlement_id', $locked->id)
                ->where('status', SettlementPayment::INITIATED)
                ->where('expires_at', '<=', now())
                ->update(['status' => SettlementPayment::EXPIRED]);

            $live = SettlementPayment::query()
                ->where('settlement_id', $locked->id)
                ->where('status', SettlementPayment::INITIATED)
                ->whereNotNull('authorization_url')
                ->where('expires_at', '>', now())
                ->orderByDesc('id')
                ->first();

            if ($live !== null) {
                return $live;
            }

            $payment = SettlementPayment::create([
                'settlement_id' => $locked->id,
                'provider' => 'paystack',
                'reference' => 'AUC-'.strtoupper((string) Str::ulid()),
                'amount_cents' => $locked->amount_cents,
                'currency' => $locked->currency,
                'status' => SettlementPayment::INITIATED,
                'initiated_by' => $user->getKey(),
                'expires_at' => now()->addMinutes($this->config->attemptTtlMinutes()),
            ]);

            $result = $this->client->initializeTransaction(
                email: (string) $user->email,
                amountCents: $locked->amount_cents,
                currency: $locked->currency,
                reference: $payment->reference,
                callbackUrl: route('settlements.payment.callback', $locked, true),
                metadata: ['settlement_id' => $locked->id, 'auction_id' => $locked->auction_id],
            );

            if ($result->reference !== $payment->reference) {
                throw new PaymentGatewayException('Paystack sent an answer this app could not understand. Nothing was charged. Please try again later.', 'unexpected_response', true);
            }

            $payment->update(['authorization_url' => $result->authorizationUrl]);

            return $payment;
        });
    }

    /**
     * @return string one of the SettlementPaymentRecorder outcomes, or 'no_attempt'
     *
     * @throws PaymentGatewayException when Paystack could not be asked (nothing was changed)
     */
    public function confirm(AuctionSettlement $settlement): string
    {
        $fresh = AuctionSettlement::query()->find($settlement->getKey());

        if ($fresh === null) {
            return 'no_attempt';
        }

        if ($fresh->isPaid()) {
            return SettlementPaymentRecorder::ALREADY_PAID;
        }

        $attempt = SettlementPayment::query()
            ->where('settlement_id', $fresh->id)
            ->whereIn('status', [SettlementPayment::INITIATED, SettlementPayment::EXPIRED])
            ->orderByDesc('id')
            ->first();

        if ($attempt === null) {
            return 'no_attempt';
        }

        return $this->recorder->apply($this->client->verifyTransaction($attempt->reference), SettlementPaymentRecorder::FROM_CALLBACK, null, $attempt);
    }
}
