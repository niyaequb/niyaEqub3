<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rewrite historical `chapa` contributions as `dashen`.
 *
 * WHY THE OLD VALUE IS NOT KEPT AS A SEPARATE ENUM CASE
 *
 * `payment_method` is cast to App\Enums\EqubPaymentMethod, and PHP throws on
 * casting a value the enum does not have. Dropping the Chapa case without this
 * migration would take out every screen that lists a historical contribution —
 * the member's payment history, the admin register, the reports — not just the
 * payment path. Keeping a Chapa case instead would leave a live-looking option
 * in the admin form for a gateway the platform can no longer reach.
 *
 * So the rows move. What is lost is the ability to tell, from this column
 * alone, which processor took a payment made before the migration. That
 * information is not gone: those rows are identifiable by `created_at` against
 * the deploy date, and the transaction detail lives in the application logs and
 * in Chapa's own dashboard, which is where anyone reconciling a pre-migration
 * settlement would have to look regardless.
 *
 * `down()` restores the old value for the rows this migration touched, so a
 * rollback lands where it started rather than half-way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('equb_payments') || ! Schema::hasColumn('equb_payments', 'payment_method')) {
            return;
        }

        DB::table('equb_payments')
            ->where('payment_method', 'chapa')
            ->update([
                'payment_method' => 'dashen',
                // Left alone on purpose: `updated_at` on a settled contribution
                // is effectively its settlement time, and reconciliation reads
                // it that way. A bulk rewrite would restamp every historical
                // payment with today's date and destroy that.
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('equb_payments') || ! Schema::hasColumn('equb_payments', 'payment_method')) {
            return;
        }

        DB::table('equb_payments')
            ->where('payment_method', 'dashen')
            ->update(['payment_method' => 'chapa']);
    }
};
