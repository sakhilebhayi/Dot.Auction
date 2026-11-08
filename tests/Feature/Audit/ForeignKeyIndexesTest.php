<?php

namespace Tests\Feature\Audit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ForeignKeyIndexesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function indexes(): array
    {
        return [
            'auctions by seller' => ['auctions', 'auctions_seller_id_index'],
            'auctions by category' => ['auctions', 'auctions_category_id_index'],
            'bids by bidder' => ['bids', 'bids_bidder_id_index'],
            'bid history order' => ['bids', 'bids_auction_id_created_at_id_index'],
            'watchers of an auction' => ['watchlists', 'watchlists_auction_id_index'],
            'settlements by winner' => ['auction_settlements', 'auction_settlements_winner_id_index'],
            'settlements by seller' => ['auction_settlements', 'auction_settlements_seller_id_index'],
        ];
    }

    #[DataProvider('indexes')]
    public function test_the_lookup_index_exists(string $table, string $index): void
    {
        $this->assertTrue(Schema::hasIndex($table, $index), "{$table} is missing {$index}");
    }
}
