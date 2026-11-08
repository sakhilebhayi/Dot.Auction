<?php

namespace App\Console\Commands;

use App\Models\AuctionCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Every lot needs a category, and a seller cannot save a listing without choosing one, so a deployment with an empty
 * `auction_categories` table cannot list anything. This puts a sensible South African marketplace set in place.
 *
 * Idempotent and never run by itself: it adds the categories that are missing (matched by slug) and leaves every existing
 * one, including any a person renamed or removed from this list, exactly as it is. Run it by hand once on a new deployment.
 *
 *   php artisan auction:seed-categories            add what is missing
 *   php artisan auction:seed-categories --dry-run  show what it would add
 *   php artisan auction:seed-categories --check    change nothing; exit 1 when there are no categories at all (for a deploy check)
 */
class SeedCategories extends Command
{
    protected $signature = 'auction:seed-categories
                                {--dry-run : Show what would be added without writing anything}
                                {--check : Change nothing; exit with an error when no category exists}';

    protected $description = 'Add the default marketplace categories that are missing (idempotent; not run automatically)';

    /** @var array<int, string> */
    public const DEFAULTS = [
        'Antiques & Collectables',
        'Art & Prints',
        'Baby & Kids',
        'Bicycles & Cycling',
        'Books, Music & Film',
        'Building Materials & Tools',
        'Business & Industrial Equipment',
        'Cameras & Photography',
        'Cars, Bakkies & Vehicle Parts',
        'Clothing & Accessories',
        'Coins, Notes & Stamps',
        'Computers & Laptops',
        'Electronics & Audio',
        'Farming & Agricultural Equipment',
        'Furniture & Home Décor',
        'Garden & Outdoor',
        'Home Appliances',
        'Jewellery & Watches',
        'Kitchen, Braai & Dining',
        'Motorcycles & Quad Bikes',
        'Musical Instruments',
        'Phones & Tablets',
        'Sport, Camping & Fishing',
        'Toys, Games & Hobbies',
        'Other',
    ];

    public function handle(): int
    {
        if ($this->option('check')) {
            $count = AuctionCategory::query()->count();

            if ($count === 0) {
                $this->error('There are no auction categories, so nobody can list a lot. Run: php artisan auction:seed-categories');

                return self::FAILURE;
            }

            $this->info("{$count} categories exist.");

            return self::SUCCESS;
        }

        $existing = AuctionCategory::query()->pluck('slug')->flip();
        $added = [];

        foreach (self::DEFAULTS as $name) {
            $slug = Str::slug($name);

            if (isset($existing[$slug])) {
                continue;
            }

            if (! $this->option('dry-run')) {
                AuctionCategory::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
            }

            $added[] = $name;
        }

        if ($added === []) {
            $this->info('All '.count(self::DEFAULTS).' default categories are already there. Nothing changed.');

            return self::SUCCESS;
        }

        $this->info(($this->option('dry-run') ? 'Would add ' : 'Added ').count($added).' categories: '.implode(', ', $added).'.');

        return self::SUCCESS;
    }
}
