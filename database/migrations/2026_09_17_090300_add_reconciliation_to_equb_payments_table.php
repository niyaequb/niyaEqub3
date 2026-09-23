<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a contribution has been checked against the bank, and by whom.
 *
 * `status = paid` says the money was credited. It does not say anyone ever
 * confirmed it arrived — a row marked paid by an operator on the strength of
 * a screenshot looks identical in that column to one the bank confirmed. That
 * distinction is the whole of reconciliation, so it gets its own field rather
 * than being inferred from whether bank_transaction_id happens to be filled.
 *
 * `reconciled_by` being nullable matters: a row matched automatically against
 * a statement line is reconciled with no person involved, and that is a
 * stronger form of evidence than a human ticking a box, not a weaker one.
 * Null here means "the machine matched it", not "nobody checked".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equb_payments', function (Blueprint $table) {
            $table->timestamp('reconciled_at')->nullable()->after('bank_payload');
            $table->foreignId('reconciled_by')->nullable()->after('reconciled_at')
                ->constrained('users')->nullOnDelete();

            // auto | statement | manual — how it came to be reconciled.
            $table->string('reconciled_via', 20)->nullable()->after('reconciled_by');
            $table->string('reconcile_note')->nullable()->after('reconciled_via');

            // Raised by the matcher and cleared when resolved: amount
            // mismatch, duplicate transaction id, and so on. A payment can be
            // paid, reconciled and still flagged, which is exactly the state
            // an operator needs to be able to find.
            $table->string('reconcile_flag', 32)->nullable()->after('reconcile_note');

            $table->index(['status', 'reconciled_at']);
            $table->index('reconcile_flag');
        });
    }

    public function down(): void
    {
        Schema::table('equb_payments', function (Blueprint $table) {
            $table->dropIndex(['status', 'reconciled_at']);
            $table->dropIndex(['reconcile_flag']);
            $table->dropConstrainedForeignId('reconciled_by');
            $table->dropColumn(['reconciled_at', 'reconciled_via', 'reconcile_note', 'reconcile_flag']);
        });
    }
};
