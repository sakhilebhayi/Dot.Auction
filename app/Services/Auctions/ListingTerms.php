<?php

namespace App\Services\Auctions;

use Carbon\CarbonInterface;

/**
 * The cross-field rules every listing must satisfy, in one place so the
 * seller form (field errors) and ListingManager (the check repeated under
 * the row lock at save and publish time) cannot drift apart. All amounts
 * are integer cents.
 */
class ListingTerms
{
    /**
     * @return array<string, string> field => message; empty when the terms are sound
     */
    public static function problems(
        int $startingCents,
        ?int $reserveCents,
        ?int $buyNowCents,
        int $incrementCents,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?CarbonInterface $now = null,
    ): array {
        $now ??= now();
        $problems = [];

        if ($reserveCents !== null && $reserveCents < $startingCents) {
            $problems['reserve_price'] = 'The reserve price must be at least the starting price.';
        }

        if ($buyNowCents !== null) {
            if ($buyNowCents < $startingCents + $incrementCents) {
                $problems['buy_now_price'] = 'The buy now price must be at least one bid increment above the starting price.';
            } elseif ($reserveCents !== null && $buyNowCents < $reserveCents) {
                $problems['buy_now_price'] = 'The buy now price cannot be below the reserve price, or a buyer could end the auction without meeting it.';
            }
        }

        if (! $endsAt->greaterThan($now)) {
            $problems['ends_at'] = 'The closing time must be in the future.';
        } elseif (! $endsAt->greaterThan($startsAt)) {
            $problems['ends_at'] = 'The closing time must be after the opening time.';
        } else {
            $opens = $startsAt->greaterThan($now) ? $startsAt : $now;
            $minMinutes = (int) config('auction.listing.min_duration_minutes', 60);
            $maxDays = (int) config('auction.listing.max_duration_days', 30);

            if ($endsAt->lessThan($opens->copy()->addMinutes($minMinutes))) {
                $problems['ends_at'] = 'An auction must run for at least '.self::minutesLabel($minMinutes).'.';
            } elseif ($endsAt->greaterThan($opens->copy()->addDays($maxDays))) {
                $problems['ends_at'] = "An auction can run for at most {$maxDays} days.";
            }
        }

        return $problems;
    }

    private static function minutesLabel(int $minutes): string
    {
        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours === 1 ? '1 hour' : "{$hours} hours";
        }

        return "{$minutes} minutes";
    }
}
