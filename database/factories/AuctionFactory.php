<?php

namespace Database\Factories;

use App\Models\Auction;
use App\Models\AuctionCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to a live auction that opened an hour ago and closes tomorrow,
 * with no bids: starting price 200.00, increment 20.00, no reserve.
 *
 * @extends Factory<Auction>
 */
class AuctionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_id' => User::factory()->withPersonalTeam(),
            'category_id' => AuctionCategory::factory(),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'starting_price' => '200.00',
            'current_price' => '200.00',
            'bid_increment' => '20.00',
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => 'draft',
            'starts_at' => now(),
            'ends_at' => now()->addDays(3),
        ]);
    }

    public function endingIn(int $minutes): static
    {
        return $this->state(fn () => ['ends_at' => now()->addMinutes($minutes)]);
    }

    public function ended(): static
    {
        return $this->state(fn () => [
            'status' => 'ended',
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subMinute(),
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(4),
        ]);
    }
}
