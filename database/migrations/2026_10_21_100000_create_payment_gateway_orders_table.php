<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps our order reference to a hosted-checkout gateway's own order id.
 *
 * EthSwitch NPG answers register.do with its own orderId and looks orders up
 * by that id, so the pair has to be kept somewhere. A new table rather than a
 * column on equb_payments: a batch is one gateway order for many contribution
 * rows, and the gateway layer is meant to know nothing about Equb.
 *
 * Additive only. Nothing existing is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_gateway_orders')) {
            return;
        }

        Schema::create('payment_gateway_orders', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32);
            $table->string('reference', 64);
            $table->string('external_order_id', 64)->nullable();
            $table->text('checkout_url')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 8);
            $table->string('status', 32)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->json('register_response')->nullable();
            $table->json('status_response')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'reference']);
            $table->index(['gateway', 'external_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_orders');
    }
};
