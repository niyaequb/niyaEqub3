<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Professional payout requests: a reference number the agent and the admin
 * can both quote, the agent's note, the admin's transfer reference / reason,
 * and when an agent cancelled a request. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_payment_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_payment_requests', 'reference')) {
                $table->string('reference', 40)->nullable()->unique();
            }
            if (! Schema::hasColumn('agent_payment_requests', 'note')) {
                $table->text('note')->nullable();
            }
            if (! Schema::hasColumn('agent_payment_requests', 'admin_note')) {
                $table->text('admin_note')->nullable();
            }
            if (! Schema::hasColumn('agent_payment_requests', 'transaction_reference')) {
                $table->string('transaction_reference')->nullable();
            }
            if (! Schema::hasColumn('agent_payment_requests', 'processed_by')) {
                $table->unsignedBigInteger('processed_by')->nullable();
            }
            if (! Schema::hasColumn('agent_payment_requests', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
        });

        // Give existing requests a reference too.
        DB::table('agent_payment_requests')->whereNull('reference')->orderBy('id')->get(['id', 'created_at'])
            ->each(function ($row): void {
                $date = $row->created_at ? date('ym', strtotime($row->created_at)) : date('ym');
                DB::table('agent_payment_requests')->where('id', $row->id)
                    ->update(['reference' => 'WD-'.$date.'-'.str_pad((string) $row->id, 5, '0', STR_PAD_LEFT)]);
            });
    }

    public function down(): void
    {
        Schema::table('agent_payment_requests', function (Blueprint $table) {
            foreach (['reference', 'note', 'admin_note', 'transaction_reference', 'processed_by', 'cancelled_at'] as $column) {
                if (Schema::hasColumn('agent_payment_requests', $column)) {
                    if ($column === 'reference') {
                        $table->dropUnique(['reference']);
                    }
                    $table->dropColumn($column);
                }
            }
        });
    }
};
