<?php

namespace App\Support;

use App\Models\Auction;
use App\Models\Bid;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Unsaved Auction models in every state, for the /dev/kit gallery (local and testing only). Nothing here touches the database.
 * The data is invented and says so on screen; it exists to show the components, not to imitate activity.
 */
final class KitData
{
    /** @return array<string, Auction> */
    public static function lots(CarbonInterface $now): array
    {
        $make = function (int $id, string $title, string $status, array $over = []) use ($now): Auction {
            $auction = new Auction(array_merge([
                'title' => $title,
                'status' => $status,
                'starting_price' => '1000.00',
                'current_price' => '1250.00',
                'bid_increment' => '50.00',
                'starts_at' => $now->copy()->subHours(20),
                'ends_at' => $now->copy()->addHours(2)->addMinutes(14),
                'snipe_extensions' => 0,
            ], $over));
            $auction->id = $id;
            $auction->setRelation('images', new Collection);
            $auction->setRelation('winningBid', null);
            $auction->bids_count = 14;
            $auction->reserve_price = $over['reserve_price'] ?? null;

            return $auction;
        };

        $sold = $make(404, 'Rolled-top oak writing desk', 'ended', ['ends_at' => $now->copy()->subHours(5), 'current_price' => '4200.00', 'starting_price' => '2500.00']);
        $sold->setRelation('winningBid', new Bid);

        return [
            'live' => $make(407, 'Stand mixer, 5 litre, cream', 'active', ['reserve_price' => '1500.00']),
            'ending' => $make(411, 'Karoo landscape, oil on board', 'active', ['ends_at' => $now->copy()->addMinutes(38), 'current_price' => '8600.00', 'starting_price' => '5000.00', 'bid_increment' => '200.00']),
            'extended' => $make(415, 'Brass ship clock, restored', 'active', ['ends_at' => $now->copy()->addMinutes(21), 'snipe_extensions' => 2, 'bids_count' => 31]),
            'scheduled' => $make(418, 'Cast iron stove, 1960s', 'active', ['starts_at' => $now->copy()->addDay(), 'ends_at' => $now->copy()->addDays(4), 'current_price' => '1000.00']),
            'sold' => $sold,
            'unsold' => $make(402, 'Set of four dining chairs', 'ended', ['ends_at' => $now->copy()->subDay(), 'current_price' => '1000.00', 'bids_count' => 0]),
            'pending' => $make(399, 'Zulu beadwork collar', 'pending_review', ['ends_at' => $now->copy()->subHours(3), 'current_price' => '3100.00', 'reserve_price' => '4000.00']),
            'cancelled' => $make(395, 'Leather camera bag', 'cancelled', ['current_price' => '1000.00']),
            'draft' => $make(421, 'Typewriter, portable', 'draft', ['current_price' => '1000.00']),
            'buynow' => $make(422, 'Field binoculars, 10x42', 'active', ['current_price' => '600.00', 'starting_price' => '400.00', 'buy_now_price' => '1100.00', 'bid_increment' => '50.00']),
        ];
    }
}
