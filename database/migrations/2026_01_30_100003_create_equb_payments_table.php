<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equb_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equb_membership_id')->constrained('equb_memberships')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamp('payment_date');
            $table->string('payment_method'); // chapa, offline, manual
            $table->string('status')->default('pending'); // pending, paid, failed
            $table->string('reference')->nullable()->unique(); // Chapa tx_ref
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equb_payments');
    }
};
