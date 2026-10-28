<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call Center "Called / Uncalled" status on each Equb place.
 * Additive only: two nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equb_memberships', function (Blueprint $table) {
            if (! Schema::hasColumn('equb_memberships', 'called_at')) {
                $table->timestamp('called_at')->nullable();
            }
            if (! Schema::hasColumn('equb_memberships', 'called_by')) {
                $table->unsignedBigInteger('called_by')->nullable();
            }
        });

        // Anyone the call center already logged a call for counts as Called.
        if (Schema::hasTable('call_center_logs')) {
            \Illuminate\Support\Facades\DB::table('call_center_logs')
                ->whereNotNull('equb_membership_id')
                ->orderBy('id')
                ->get(['equb_membership_id', 'called_by', 'created_at'])
                ->each(function ($log): void {
                    \Illuminate\Support\Facades\DB::table('equb_memberships')
                        ->where('id', $log->equb_membership_id)
                        ->update(['called_at' => $log->created_at, 'called_by' => $log->called_by]);
                });
        }
    }

    public function down(): void
    {
        Schema::table('equb_memberships', function (Blueprint $table) {
            foreach (['called_by', 'called_at'] as $column) {
                if (Schema::hasColumn('equb_memberships', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
