<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the BANK says about the transaction that settled a contribution.
 *
 * WHY THIS WAS MISSING AND WHY IT MATTERS
 *
 * Until now a settled contribution recorded one fact: status = paid. Everything
 * else on the row describes what the member OWED — the membership, the amount
 * due, the date the round falls on. Nothing described the money.
 *
 * So an admin looking at Equb Payments saw "Abera Gebru, 1,889.00, Sep 4" for a
 * payment made by Chernet Tadese Bekele on Sep 16 against account 5444…011.
 * Every one of those is correct and none of them is what you need when a member
 * says "I paid and it is not showing", or when somebody has to match a row
 * against a bank statement.
 *
 * These columns are the other half: the bank's transaction id, its own
 * reference, when the money actually moved, who moved it, and a link to the
 * receipt they can see themselves.
 *
 * NULLABLE, ALL OF THEM, ON PURPOSE
 *
 * Contributions settled before this existed have none of it and never will;
 * rows still pending have none of it yet; and a bank that does not publish a
 * field simply leaves it empty rather than forcing every gateway to invent one.
 * A null here means "not known", which is honest, and is different from zero.
 *
 * bank_payload keeps the whole verification response. Fields nobody thought to
 * model are the ones a reconciliation dispute turns on a year later, and they
 * cost nothing to keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equb_payments', function (Blueprint $table): void {
            // Indexed because "which contribution is this bank reference?" is
            // the question asked when somebody walks in holding a receipt.
            $table->string('bank_transaction_id')->nullable()->index()->after('batch_reference');

            // The bank's other reference. Dashen publish both an FT number and
            // a transaction id and quote different ones in different places.
            $table->string('bank_reference')->nullable()->after('bank_transaction_id');

            // When the money moved, which is not payment_date. payment_date is
            // the round this contribution belongs to and can be weeks earlier.
            $table->timestamp('bank_paid_at')->nullable()->after('bank_reference');

            // What the bank says was taken, kept separately from `amount`,
            // which is what we asked for. They should agree; storing both is
            // what lets anyone notice on the day they do not.
            $table->decimal('bank_amount', 12, 2)->nullable()->after('bank_paid_at');

            $table->string('bank_payer_name')->nullable()->after('bank_amount');
            $table->string('bank_payer_phone', 32)->nullable()->after('bank_payer_name');
            $table->string('bank_payer_account', 64)->nullable()->after('bank_payer_phone');

            $table->string('bank_receipt_url')->nullable()->after('bank_payer_account');

            $table->json('bank_payload')->nullable()->after('bank_receipt_url');
        });
    }

    public function down(): void
    {
        Schema::table('equb_payments', function (Blueprint $table): void {
            $table->dropColumn([
                'bank_transaction_id',
                'bank_reference',
                'bank_paid_at',
                'bank_amount',
                'bank_payer_name',
                'bank_payer_phone',
                'bank_payer_account',
                'bank_receipt_url',
                'bank_payload',
            ]);
        });
    }
};
