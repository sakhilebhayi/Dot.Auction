<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hand-off seam to the payment platform. Dot.Auction only records WHO
 * owes WHAT for a sale; it never collects money. A payment platform (not
 * yet chosen) is expected to read rows in status `awaiting_payment` and
 * move them on. One row per auction, enforced by the unique index, so the
 * listener that writes it is safe to run any number of times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('auction_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('winning_bid_id')->constrained('bids')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('winner_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('status')->default('awaiting_payment');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_settlements');
    }
};
