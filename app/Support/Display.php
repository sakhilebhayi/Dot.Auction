<?php

namespace App\Support;

use App\Models\Auction;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Facades\Route;

/**
 * Presentation helpers for the design system. Views never format money, times or links by hand.
 *
 * Time: stored UTC, shown in config('auction.display_timezone') (Africa/Johannesburg). If the backend's
 * Auction::localTime() is present in the tree it is used, otherwise the same conversion happens here, so this wrapper is the
 * single call site to swap (see docs/R42_DECISIONS.md, shell section).
 */
final class Display
{
    public static function symbol(): string
    {
        return (string) config('auction.currency_symbol', 'R');
    }

    /**
     * Split an amount into the parts <x-ds.money> styles separately. Whole units carry thousands separators (a thin
     * non-breaking space would be nicer for South African usage, but the backend's Auction::money() uses a comma and the two must agree).
     *
     * @return array{symbol: string, whole: string, cents: string, negative: bool}
     */
    public static function moneyParts(string|int|float|null $amount): array
    {
        $cents = Auction::toCents($amount);
        $negative = $cents < 0;
        $abs = abs($cents);

        return [
            'symbol' => self::symbol(),
            'whole' => number_format(intdiv($abs, 100)),
            'cents' => str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT),
            'negative' => $negative,
        ];
    }

    public static function money(string|int|float|null $amount): string
    {
        return Auction::money($amount);
    }

    /** A stored time in the display zone. $format defaults to "Fri 14 Nov, 18:30". */
    public static function time(?DateTimeInterface $at, ?string $format = null, bool $withZone = false): string
    {
        if ($at === null) {
            return '';
        }

        if (method_exists(Auction::class, 'localTime')) {
            return Auction::localTime($at, $format, $withZone);
        }

        $local = Carbon::instance($at instanceof CarbonInterface ? $at->toDateTime() : $at)
            ->setTimezone((string) config('auction.display_timezone', 'Africa/Johannesburg'));

        return $local->format($format ?? 'D j M, H:i').($withZone ? ' '.$local->format('T') : '');
    }

    /**
     * Remaining time as a short phrase. The JavaScript countdown uses the same thresholds so the server text and the
     * ticking text never disagree: 2d 4h, 3h 12m, 12m 05s, 45s.
     */
    public static function remaining(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return match (true) {
            $d > 0 => $d.'d '.$h.'h',
            $h > 0 => $h.'h '.str_pad((string) $m, 2, '0', STR_PAD_LEFT).'m',
            $m > 0 => $m.'m '.str_pad((string) $s, 2, '0', STR_PAD_LEFT).'s',
            default => $s.'s',
        };
    }

    /**
     * [value, unit] pairs for the countdown, same thresholds as remaining(): 2d 4h, 3h 12m, 12m 05s, 45s.
     * The JavaScript in resources/js/ds.js builds the identical markup, so there is no jump when it takes over.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function remainingParts(int $seconds): array
    {
        $seconds = max(0, $seconds);
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        $two = fn (int $n): string => str_pad((string) $n, 2, '0', STR_PAD_LEFT);

        return match (true) {
            $d > 0 => [[(string) $d, 'd'], [(string) $h, 'h']],
            $h > 0 => [[(string) $h, 'h'], [$two($m), 'm']],
            $m > 0 => [[(string) $m, 'm'], [$two($s), 's']],
            default => [[(string) $s, 's']],
        };
    }

    /**
     * The lot state used by <x-ds.status>: draft | cancelled | pending | scheduled | ending | live | sold | unsold.
     * "pending" is a lot that ended below its reserve and waits for the seller (status pending_review).
     * "ending" is a live lot inside config auction.ending_soon_window_minutes.
     */
    public static function lotState(Auction $auction): string
    {
        $status = (string) $auction->status;

        if ($status === 'draft' || $status === 'cancelled') {
            return $status;
        }
        if ($status === 'pending_review') {
            return 'pending';
        }
        if ($status === 'ended') {
            return $auction->winningBid !== null ? 'sold' : 'unsold';
        }
        if ($auction->isScheduled()) {
            return 'scheduled';
        }
        if ($status === 'active' && $auction->ends_at->isPast()) {
            return 'unsold'; // closed, the settle job has not run yet; shown as ended rather than live
        }
        if ($auction->ends_at->lte(now()->addMinutes((int) config('auction.ending_soon_window_minutes', 60)))) {
            return 'ending';
        }

        return 'live';
    }

    /**
     * Word, long form, silhouette and hue for every state. The single table behind <x-ds.status> (DESIGN.md lists the same).
     * tone is a CSS modifier (ds-status--live and so on), not a colour: the colours are tokens.
     *
     * @return array<string, array{word: string, long: ?string, icon: string, tone: string}>
     */
    public static function statusVocabulary(): array
    {
        return [
            'live' => ['word' => 'Live', 'long' => 'Open for bids', 'icon' => 'play', 'tone' => 'live'],
            'ending' => ['word' => 'Closing soon', 'long' => null, 'icon' => 'hourglass', 'tone' => 'urgent'],
            'scheduled' => ['word' => 'Scheduled', 'long' => 'Not open yet', 'icon' => 'calendar', 'tone' => 'scheduled'],
            'sold' => ['word' => 'Sold', 'long' => 'Sold, the hammer has fallen', 'icon' => 'gavel', 'tone' => 'ink'],
            'unsold' => ['word' => 'Not sold', 'long' => 'Ended without a sale', 'icon' => 'no-sale', 'tone' => 'muted'],
            'pending' => ['word' => 'Awaiting seller decision', 'long' => 'Ended below the reserve, the seller is deciding', 'icon' => 'pause', 'tone' => 'notice'],
            'cancelled' => ['word' => 'Cancelled', 'long' => null, 'icon' => 'ban', 'tone' => 'muted'],
            'extended' => ['word' => 'Extended', 'long' => 'Closing time extended by a late bid', 'icon' => 'extend', 'tone' => 'live'],
            'draft' => ['word' => 'Draft', 'long' => 'Not published', 'icon' => 'pencil', 'tone' => 'muted'],
            // Settlements
            'awaiting_payment' => ['word' => 'Awaiting payment', 'long' => null, 'icon' => 'clock', 'tone' => 'notice'],
            'initiated' => ['word' => 'Payment started', 'long' => null, 'icon' => 'clock', 'tone' => 'notice'],
            'paid' => ['word' => 'Paid', 'long' => null, 'icon' => 'check-circle', 'tone' => 'live'],
            'payment_overdue' => ['word' => 'Payment overdue', 'long' => null, 'icon' => 'alert', 'tone' => 'urgent'],
            'failed' => ['word' => 'Payment failed', 'long' => null, 'icon' => 'x-circle', 'tone' => 'urgent'],
            'unmatched' => ['word' => 'Needs a person', 'long' => null, 'icon' => 'alert', 'tone' => 'urgent'],
            'expired' => ['word' => 'Expired', 'long' => null, 'icon' => 'ban', 'tone' => 'muted'],
        ];
    }

    /** A link to a named route, or '#' while the route is not registered yet (UI agents register the missing names). */
    public static function route(string $name, mixed $parameters = [], bool $absolute = true): string
    {
        return Route::has($name) ? route($name, $parameters, $absolute) : '#';
    }

    public static function routeExists(string $name): bool
    {
        return Route::has($name);
    }

    /**
     * Two initials for an avatar. "Thandi Mokoena" gives "TM", "thandi" gives "T".
     */
    public static function initials(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return '?';
        }
        $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
        $last = count($parts) > 1 ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) : '';

        return $first.$last;
    }
}
