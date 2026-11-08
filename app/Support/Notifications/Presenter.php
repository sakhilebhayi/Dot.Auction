<?php

namespace App\Support\Notifications;

/**
 * What a stored notification looks like on screen: the icon that goes with its kind (the icon set in DESIGN.md), and its
 * copy. The words themselves are written where the notification is created (app/Notifications), in plain sentences.
 */
final class Presenter
{
    public static function icon(?string $type): string
    {
        return match (true) {
            $type === 'outbid' => 'bids',
            $type === 'auction_won' => 'trophy',
            $type === 'auction_sold' => 'gavel',
            $type === 'auction_ending_soon' => 'hourglass',
            $type === 'reserve_not_met' => 'pause',
            is_string($type) && str_starts_with($type, 'settlement_') => 'receipt',
            default => 'bell',
        };
    }
}
