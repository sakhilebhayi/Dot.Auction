<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per payment attempt (and per payment event Paystack tells us about that we cannot match).
 *
 * `reference` is ours, unique, and is what Paystack echoes back. unique(provider, provider_transaction_id)
 * is the backstop that stops one Paystack transaction ever settling two sales; it is NULL until a
 * payment is confirmed, and NULLs do not collide. `settlement_id` is NULL for an event whose
 * reference matches nothing here (kept for an operator); it is nulled, not cascaded, if the
 * settlement is ever deleted, so a record of money received outlives the sale row.
 *
 * Amounts are integer cents. `summary` is a short human note, never a raw provider payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('settlement_id')->nullable()->constrained('auction_settlements')->nullOnDelete();
            $table->string('provider', 20)->default('paystack');
            $table->string('reference', 191)->unique();
            $table->unsignedBigInteger('amount_cents')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('status', 20)->default('initiated');
            $table->string('reason', 40)->nullable();
            $table->string('provider_transaction_id', 64)->nullable();
            $table->text('authorization_url')->nullable();
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_transaction_id']);
            $table->index(['settlement_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_payments');
    }
};
