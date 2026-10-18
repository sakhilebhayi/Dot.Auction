<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment state for a settlement. Additive only: every new column is nullable, `status` stays a plain
 * string (awaiting_payment | paid | payment_overdue | cancelled), and no existing row is touched.
 *
 * A legacy awaiting_payment row keeps payment_due_at NULL on purpose. Its winner was told nothing was
 * due, so a deadline counted from the old created_at would put them overdue the moment payment is
 * switched on. The scheduled command starts that row's clock when payment first opens.
 *
 * settlement_events is the audit trail of decisions: who/what moved a settlement and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_settlements', function (Blueprint $table) {
            $table->timestamp('payment_due_at')->nullable()->after('status');
            $table->timestamp('paid_at')->nullable()->after('payment_due_at');
            $table->timestamp('overdue_at')->nullable()->after('paid_at');
            $table->timestamp('cancelled_at')->nullable()->after('overdue_at');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');

            $table->index(['status', 'payment_due_at']);
        });

        Schema::create('settlement_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('settlement_id')->constrained('auction_settlements')->cascadeOnDelete();
            // Null when the system acted (the scheduled deadline, a payment confirmation).
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 40);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['settlement_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_events');

        Schema::table('auction_settlements', function (Blueprint $table) {
            $table->dropIndex(['status', 'payment_due_at']);
            $table->dropColumn(['payment_due_at', 'paid_at', 'overdue_at', 'cancelled_at', 'cancelled_by']);
        });
    }
};
