<?php

namespace App\Http\Requests\Seller;

use App\Models\Auction;
use App\Rules\MoneyAmount;
use App\Services\Auctions\ListingTerms;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create and edit share one form. For an edit, the rules depend on what the
 * auction already is: once a bid exists only the description is accepted,
 * and an auction that has already opened keeps its opening time.
 */
class SaveListingRequest extends FormRequest
{
    private const DATE_FORMATS = ['Y-m-d\TH:i', 'Y-m-d\TH:i:s'];

    public function authorize(): bool
    {
        $auction = $this->auction();

        return $auction !== null
            ? $this->user()->can('update', $auction)
            : $this->user()->can('create', Auction::class);
    }

    public function auction(): ?Auction
    {
        $auction = $this->route('auction');

        return $auction instanceof Auction ? $auction : null;
    }

    public function termsLocked(): bool
    {
        return $this->auction()?->hasBids() ?? false;
    }

    private function opensAtIsFixed(): bool
    {
        $auction = $this->auction();

        return $auction !== null && $auction->status === 'active' && ! $auction->starts_at->isFuture();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $description = ['nullable', 'string', 'max:5000'];

        if ($this->termsLocked()) {
            return ['description' => $description];
        }

        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => $description,
            'category_id' => ['required', 'integer', Rule::exists('auction_categories', 'id')],
            'starting_price' => ['required', new MoneyAmount],
            'reserve_price' => ['nullable', new MoneyAmount],
            'buy_now_price' => ['nullable', new MoneyAmount],
            'bid_increment' => ['required', new MoneyAmount],
            'starts_at' => ['nullable', $this->dateRule()],
            'ends_at' => ['required', $this->dateRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category_id' => 'category',
            'starting_price' => 'starting price',
            'reserve_price' => 'reserve price',
            'buy_now_price' => 'buy now price',
            'bid_increment' => 'bid increment',
            'starts_at' => 'opening time',
            'ends_at' => 'closing time',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->termsLocked() || $validator->errors()->isNotEmpty()) {
                return;
            }

            $input = $validator->validated();

            $problems = ListingTerms::problems(
                MoneyAmount::toCents($input['starting_price']),
                $this->cents($input['reserve_price'] ?? null),
                $this->cents($input['buy_now_price'] ?? null),
                MoneyAmount::toCents($input['bid_increment']),
                $this->openingTime($input),
                self::parseDate($input['ends_at']),
            );

            foreach ($problems as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        }];
    }

    /**
     * The validated input in the shape ListingManager takes: money as exact
     * 2-decimal strings, dates as Carbon. When terms are locked this is the
     * description alone, so no other field can reach the model.
     *
     * @return array<string, mixed>
     */
    public function listingData(): array
    {
        $input = $this->validated();

        if ($this->termsLocked()) {
            return ['description' => $input['description'] ?? null];
        }

        $data = [
            'title' => trim($input['title']),
            'description' => $input['description'] ?? null,
            'category_id' => (int) $input['category_id'],
            'starting_price' => Auction::fromCents(MoneyAmount::toCents($input['starting_price'])),
            'reserve_price' => $this->money($input['reserve_price'] ?? null),
            'buy_now_price' => $this->money($input['buy_now_price'] ?? null),
            'bid_increment' => Auction::fromCents(MoneyAmount::toCents($input['bid_increment'])),
            'ends_at' => self::parseDate($input['ends_at']),
        ];

        if (! $this->opensAtIsFixed()) {
            $data['starts_at'] = isset($input['starts_at']) ? self::parseDate($input['starts_at']) : null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function openingTime(array $input): CarbonInterface
    {
        if ($this->opensAtIsFixed()) {
            return $this->auction()->starts_at;
        }

        $startsAt = isset($input['starts_at']) ? self::parseDate($input['starts_at']) : null;

        return $startsAt !== null && $startsAt->isFuture() ? $startsAt : now();
    }

    private function cents(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : MoneyAmount::toCents($value);
    }

    private function money(mixed $value): ?string
    {
        $cents = $this->cents($value);

        return $cents === null ? null : Auction::fromCents($cents);
    }

    private function dateRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || self::parseDate($value) === null) {
                $fail('Enter the :attribute as a date and time.');
            }
        };
    }

    /**
     * Interprets a datetime-local value in the application timezone.
     * Rejects overflowing dates such as 31 February.
     */
    public static function parseDate(string $value): ?Carbon
    {
        foreach (self::DATE_FORMATS as $format) {
            if (Carbon::hasFormat($value, $format)) {
                $date = Carbon::createFromFormat($format, $value, config('app.timezone'));

                if ($date !== null && $date->format($format) === $value) {
                    return $date;
                }
            }
        }

        return null;
    }
}
