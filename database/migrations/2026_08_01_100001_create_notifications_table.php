<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard `database` notification channel table (matches the
 * output of `php artisan notifications:table`), added for the in-app
 * notification bell so auction events (outbid, auction won, auction ending
 * soon) have somewhere to land. OutbidNotification is dispatched
 * automatically from App\Livewire\Auctions\BidPanel; AuctionWonNotification
 * and AuctionEndingSoonNotification are not yet auto-triggered — see
 * wiki.md §6 roadmap (no settlement job / scheduled sweep exists yet).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
