<?php

namespace App\Rules;

use App\Models\Auction;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A positive money amount typed as plain digits with at most two decimals
 * ("1250", "1250.5", "1250.50"). Deliberately stricter than is_numeric():
 * no exponents ("1e3"), signs, spaces or thousands separators, so what the
 * seller typed is exactly what is stored. The value is compared as integer
 * cents, never as a float.
 */
class MoneyAmount implements ValidationRule
{
    public static function pattern(): string
    {
        return '/^\d{1,12}(?:\.\d{1,2})?$/';
    }

    /**
     * Cents for a value that passed this rule, or null if it did not.
     */
    public static function toCents(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match(self::pattern(), $value) !== 1) {
            return null;
        }

        $cents = Auction::toCents($value);
        $limit = ((int) config('auction.listing.max_price', 100000000)) * 100;

        return $cents > 0 && $cents <= $limit ? $cents : null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::toCents($value) === null) {
            $max = number_format((int) config('auction.listing.max_price', 100000000));
            $fail("Enter the :attribute as an amount above 0 and up to {$max}, with at most 2 decimals and no commas or symbols, for example 1250.50.");
        }
    }
}
