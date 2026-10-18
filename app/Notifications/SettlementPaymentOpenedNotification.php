<?php

namespace App\Notifications;

use App\Models\AuctionSettlement;
use App\Notifications\Concerns\DescribesSettlement;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Notifications\Notification;

/**
 * In-app notice to a winner whose sale was recorded before online payment existed: it is open now.
 * Keeps the promise the earlier "you will be told when the payment step opens" notice made.
 */
class SettlementPaymentOpenedNotification extends Notification
{
    use DescribesSettlement;

    public function __construct(public AuctionSettlement $settlement) {}

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $hours = app(PaystackConfig::class)->paymentWindowHours();

        return $this->payload(
            $this->settlement,
            'settlement_payment_open',
            'You can pay now',
            'Payment is now open for '.$this->lot($this->settlement).' ('.$this->settlement->amount()."). Pay within {$hours} hours from its payment page.".$this->testModeNote(),
        );
    }
}
