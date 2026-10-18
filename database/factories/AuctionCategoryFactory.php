<?php

namespace Database\Factories;

use App\Models\AuctionCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuctionCategory>
 */
class AuctionCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucfirst($name),
            'slug' => str($name)->slug()->toString(),
        ];
    }
}
