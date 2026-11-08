<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the seller was told a reserve-not-met decision is waiting for them. Claimed with a conditional UPDATE so the
 * notice goes out once however many times the event is delivered (R-42 F-09). Additive and nullable: existing
 * proposals keep NULL and are never back-notified (the listener only runs for a new ReserveNotMet event).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reserve_not_met_proposals', 'seller_notified_at')) {
            Schema::table('reserve_not_met_proposals', function (Blueprint $table) {
                $table->timestamp('seller_notified_at')->nullable()->after('decided_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('reserve_not_met_proposals', 'seller_notified_at')) {
            Schema::table('reserve_not_met_proposals', function (Blueprint $table) {
                $table->dropColumn('seller_notified_at');
            });
        }
    }
};
