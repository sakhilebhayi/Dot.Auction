<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Where people read and type times (R-42, F-11).
 *
 * Storage stays UTC: `config('app.timezone')` is 'UTC' and must not change, or every stored timestamp would shift.
 * What people see and type is in the DISPLAY zone, `config('auction.display_timezone')` (env
 * AUCTION_DISPLAY_TIMEZONE, default Africa/Johannesburg). Everything that prints a time to a person, or reads one
 * from a form, goes through here.
 *
 *   DisplayTime::format($at, 'j M Y, H:i', true)   "8 Nov 2026, 18:00 SAST"
 *   DisplayTime::toLocal($at)                      a Carbon copy in the display zone
 *   DisplayTime::inputValue($at)                   "2026-11-08T18:00", the value of <input type="datetime-local">
 *   DisplayTime::parseInput('2026-11-08T18:00')    Carbon in UTC (16:00Z), or null when not a real date
 *   DisplayTime::iso($at)                          ISO 8601 with the display offset ("...+02:00"), for e-mail data
 *   DisplayTime::zone() / abbreviation() / label() "Africa/Johannesburg" / "SAST" / "SAST (Africa/Johannesburg)"
 *
 * Also available as `$carbon->local()` and `$carbon->localFormat($format, $withZone)` (Carbon macros),
 * `Auction::localTime($at, $format, $withZone)`, and the Blade directive `@localtime($at, 'j M Y, H:i', true)`.
 */
final class DisplayTime
{
    public const DEFAULT_ZONE = 'Africa/Johannesburg';

    public const DEFAULT_FORMAT = 'j M Y, H:i';

    /** The value format of a datetime-local input. */
    public const INPUT_FORMAT = 'Y-m-d\TH:i';

    /** Formats a datetime-local input may submit (browsers add seconds when the step is under a minute). */
    private const INPUT_FORMATS = ['Y-m-d\TH:i', 'Y-m-d\TH:i:s'];

    /**
     * The configured display zone; an unknown zone name falls back to Africa/Johannesburg rather than crashing pages.
     */
    public static function zone(): string
    {
        $zone = (string) config('auction.display_timezone', self::DEFAULT_ZONE);

        try {
            new \DateTimeZone($zone);
        } catch (Throwable) {
            return self::DEFAULT_ZONE;
        }

        return $zone;
    }

    /** "SAST" for Africa/Johannesburg; for a zone without a letter abbreviation PHP gives an offset such as "+03". */
    public static function abbreviation(?DateTimeInterface $at = null): string
    {
        return Carbon::instance($at ?? now())->setTimezone(self::zone())->format('T');
    }

    /** "SAST (Africa/Johannesburg)", for help text next to a time input. */
    public static function label(): string
    {
        return self::abbreviation().' ('.self::zone().')';
    }

    /**
     * A copy of the instant in the display zone. The same instant, so comparisons are unaffected; null stays null.
     */
    public static function toLocal(DateTimeInterface|string|null $date): ?Carbon
    {
        if ($date === null || $date === '') {
            return null;
        }

        $carbon = $date instanceof DateTimeInterface
            ? Carbon::instance($date)
            : Carbon::parse($date, 'UTC');

        return $carbon->setTimezone(self::zone());
    }

    /**
     * Print a time for a person. Null prints as an empty string. `$withZone` appends the abbreviation ("SAST").
     */
    public static function format(DateTimeInterface|string|null $date, ?string $format = null, bool $withZone = false): string
    {
        $local = self::toLocal($date);

        if ($local === null) {
            return '';
        }

        $text = $local->format($format ?? self::DEFAULT_FORMAT);

        return $withZone ? $text.' '.$local->format('T') : $text;
    }

    /** The value to put in a datetime-local input, in the display zone. */
    public static function inputValue(DateTimeInterface|string|null $date): string
    {
        return self::toLocal($date)?->format(self::INPUT_FORMAT) ?? '';
    }

    /**
     * What a datetime-local input sent, interpreted as display-zone wall-clock time and returned as a UTC Carbon (what
     * gets stored). Null for text that is not a real date and time, including overflows such as 31 February.
     */
    public static function parseInput(mixed $value): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        foreach (self::INPUT_FORMATS as $format) {
            if (! Carbon::hasFormat($value, $format)) {
                continue;
            }

            $date = Carbon::createFromFormat($format, $value, self::zone());

            if ($date !== null && $date->format($format) === $value) {
                return $date->setTimezone('UTC');
            }
        }

        return null;
    }

    /**
     * ISO 8601 carrying the display offset ("2026-11-08T18:00:00+02:00"): the same instant as the UTC form, so a
     * reader that only looks at the offset-less part is not misled by a Z. Used in Dot.Notify event data.
     */
    public static function iso(DateTimeInterface|string|null $date): ?string
    {
        return self::toLocal($date)?->toIso8601String();
    }
}
