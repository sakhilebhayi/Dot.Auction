<?php

namespace App\Notifications;

use App\Models\Auction;
use App\Models\ReserveNotMetProposal;
use App\Notifications\Concerns\SendsNotifyEvent;
use App\Services\Notify\NotifyClient;
use Illuminate\Notifications\Notification;

/**
 * Tells a seller their auction closed below its reserve and that the decision (accept the top bid, or end it unsold)
 * is theirs. Sent once per proposal by App\Listeners\NotifySellerOfReserveNotMet. The decision panel is on the
 * dashboard under "Awaiting Your Decision". The bidder's name is not included.
 */
class ReserveNotMetNotification extends Notification
{
    use SendsNotifyEvent;

    public function __construct(public ReserveNotMetProposal $proposal) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', ...$this->notifyChannel()];
    }

    /**
     * The e-mail copy for Dot.Notify (`auction.reserve_not_met`), only when config notify.reserve_not_met_event is on
     * (Notify must have the event in its catalogue first). The proposal id is the bucket, so a replay collapses.
     *
     * @return array{event: string, subject: int, bucket: int, data: array<string, string>}|null
     */
    public function toNotifyEvent(object $notifiable): ?array
    {
        if (! config('notify.reserve_not_met_event')) {
            return null;
        }

        $auction = $this->proposal->auction;
        $notify = app(NotifyClient::class);

        return [
            'event' => 'auction.reserve_not_met',
            'subject' => $auction->id,
            'bucket' => $this->proposal->id,
            'data' => [
                'auction_title' => (string) $auction->title,
                'current_price' => $notify->money($auction->current_price),
                'currency' => (string) config('auction.currency_code', 'ZAR'),
                'url' => $notify->pageUrl('dashboard'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $auction = $this->proposal->auction;

        return [
            'type' => 'reserve_not_met',
            'title' => 'Your decision is needed',
            'message' => "\"{$auction->title}\" closed at ".Auction::money($auction->current_price).', below your reserve. Accept the top bid or end the auction unsold from your dashboard.',
            'auction_id' => $auction->id,
            'proposal_id' => $this->proposal->id,
            'url' => route('dashboard'),
        ];
    }
}
