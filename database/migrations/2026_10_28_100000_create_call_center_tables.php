<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call Center and Termination.
 *
 * call_center_logs  - every call the call center makes to a member about an
 *                     Equb place, with the outcome and the member's feedback.
 * equb_terminations - the permanent record of a member removed from an Equb
 *                     for not paying (and of any later reinstatement).
 * equb_memberships.termination_requested_at - set when the call center sends
 *                     a member to the Termination page.
 *
 * Additive only: new tables and one nullable column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('call_center_logs')) {
            Schema::create('call_center_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('equb_membership_id')->nullable()->constrained('equb_memberships')->nullOnDelete();
                $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
                $table->foreignId('called_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('outcome', 40);
                $table->date('promised_payment_date')->nullable();
                $table->text('feedback')->nullable();
                $table->decimal('arrears_at_call', 12, 2)->default(0);
                $table->unsignedInteger('days_unpaid_at_call')->default(0);
                $table->timestamps();

                $table->index(['equb_membership_id', 'created_at']);
                $table->index('outcome');
            });
        }

        if (! Schema::hasTable('equb_terminations')) {
            Schema::create('equb_terminations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('equb_membership_id')->nullable()->constrained('equb_memberships')->nullOnDelete();
                $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
                $table->foreignId('terminated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reason', 60);
                $table->text('note')->nullable();
                $table->decimal('arrears_at_termination', 12, 2)->default(0);
                $table->decimal('paid_at_termination', 12, 2)->default(0);
                $table->unsignedInteger('days_unpaid')->default(0);
                $table->boolean('had_won')->default(false);
                $table->timestamp('reinstated_at')->nullable();
                $table->foreignId('reinstated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('equb_memberships', 'termination_requested_at')) {
            Schema::table('equb_memberships', function (Blueprint $table) {
                $table->timestamp('termination_requested_at')->nullable();
            });
        }

        // Permissions, so a "Call Center" role can be given just these pages.
        if (Schema::hasTable('permissions') && class_exists(\Spatie\Permission\Models\Permission::class)) {
            foreach (['call-center.index', 'terminations.index', 'terminations.edit'] as $name) {
                \Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
            }

            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('equb_memberships', 'termination_requested_at')) {
            Schema::table('equb_memberships', function (Blueprint $table) {
                $table->dropColumn('termination_requested_at');
            });
        }

        Schema::dropIfExists('equb_terminations');
        Schema::dropIfExists('call_center_logs');
    }
};
