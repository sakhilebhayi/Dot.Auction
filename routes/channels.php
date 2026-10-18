<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| App\Events\BidPlaced broadcasts on a public "auction.{id}" channel, not
| a private one -- AuctionPolicy::view() already treats a published
| auction (and its bids) as visible to any authenticated user, matching a
| public marketplace listing. No authorization callback is needed for a
| public channel, so it's not registered here.
|
| The only private channel is the default Laravel broadcast notification
| channel, used by OutbidNotification and the Notification Bell.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return is_string($id) && ctype_digit($id) && $user->id === (int) $id;
});
