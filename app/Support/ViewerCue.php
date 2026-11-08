<?php

namespace App\Support;

use App\Models\Auction;

/**
 * What a lot is to the person looking at it, in one word: "You lead", "Outbid", "You won" or "Your lot". Derived from the
 * per-viewer columns App\Services\Auctions\LotBrowser puts on a lot (viewer_leads, viewer_top_bid) and the lot's state, so
 * a list needs no query per row. Returns null when the lot is nothing in particular to this person.
 */
final class ViewerCue
{
    public static function for(Auction $auction, ?int $userId, ?string $state = null): ?string
    {
        if ($userId === null) {
            return null;
        }

        if ((int) $auction->seller_id === $userId) {
            return 'own';
        }

        $state ??= Display::lotState($auction);
        $attrs = $auction->getAttributes();
        $leads = (bool) ($attrs['viewer_leads'] ?? false);
        $bid = $attrs['viewer_top_bid'] ?? null;

        return match (true) {
            $leads && in_array($state, ['live', 'ending', 'pending'], true) => 'leading',
            $leads && $state === 'sold' => 'won',
            $bid !== null && in_array($state, ['live', 'ending'], true) => 'outbid',
            default => null,
        };
    }

    /**
     * @return array{word: string, icon: string}
     */
    public static function describe(string $cue): array
    {
        return match ($cue) {
            'leading' => ['word' => 'You lead', 'icon' => 'check-circle'],
            'won' => ['word' => 'You won', 'icon' => 'trophy'],
            'outbid' => ['word' => 'Outbid', 'icon' => 'alert'],
            default => ['word' => 'Your lot', 'icon' => 'tag'],
        };
    }
}
