<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_payout_id')->constrained('agent_payouts')->cascadeOnDelete();
            $table->foreignId('agent_commission_id')->constrained('agent_commissions')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_payout_items');
    }
};
