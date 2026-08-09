<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE auctions DROP CONSTRAINT IF EXISTS auctions_status_check');
        }

        Schema::table('auctions', function (Blueprint $table) {
            $table->string('status')->default('draft')->change();
        });
    }

    public function down(): void
    {
        // Widening to a plain string is not meaningfully reversible back to
        // a narrower native enum without knowing every value already
        // stored -- intentionally a no-op, matching this program's
        // established convention for this exact migration shape.
    }
};
