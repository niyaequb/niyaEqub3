<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets one Chapa transaction settle several contributions at once.
 *
 * A member holding places for "My Responsibility People" owes one contribution
 * per place, every round. Each place keeps its own equb_payments row — the
 * ledger, the draw eligibility and the payment schedule all count per place, so
 * merging them into a single row would quietly break all three.
 *
 * But nobody wants to be sent through checkout three times to settle one round.
 * batch_reference is the seam: the rows stay separate, while sharing one
 * reference that the payment gateway sees as a single charge. When the webhook
 * confirms that reference, every row carrying it is marked paid together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equb_payments', function (Blueprint $table) {
            $table->string('batch_reference', 64)
                ->nullable()
                ->after('reference');

            // The webhook's only lookup: find every contribution settled by
            // this one transaction.
            $table->index('batch_reference');
        });
    }

    public function down(): void
    {
        Schema::table('equb_payments', function (Blueprint $table) {
            $table->dropIndex(['batch_reference']);
            $table->dropColumn('batch_reference');
        });
    }
};
