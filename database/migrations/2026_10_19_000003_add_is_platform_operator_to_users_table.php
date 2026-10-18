<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform operators run the Auction deployment itself (the payment-operations pages); this is never
 * a team role. Same column and default as Dot.Tutor, Dot.Emall, Dot.Design, Dot.Ehail and Dot.Analytics.
 * Granted out of band (php artisan auction:operator), never through mass assignment.
 *
 * Additive and safe on a populated table: every existing row gets false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_operator')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_operator');
        });
    }
};
