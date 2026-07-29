<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('registered_via')->default('direct')->after('agent_id');
            $table->string('referral_code_used')->nullable()->after('registered_via');
            $table->timestamp('registered_at')->useCurrent()->after('referral_code_used');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
        });

        if (Schema::hasTable('agents')) {
            $existingUserIds = DB::table('agents')->pluck('user_id')->all();

            DB::table('users')
                ->where('type', 'agent')
                ->whereNotIn('id', $existingUserIds)
                ->orderBy('id')
                ->get()
                ->each(function ($user): void {
                    DB::table('agents')->insert([
                        'user_id' => $user->id,
                        'referral_code' => 'AGT'.(string) $user->id,
                        'commission_rule_id' => null,
                        'is_active' => true,
                        'joined_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });

            $membersWithAgentUserId = DB::table('members')->whereNotNull('agent_id')->get();
            foreach ($membersWithAgentUserId as $member) {
                $agent = DB::table('agents')->where('user_id', $member->agent_id)->first();
                if ($agent) {
                    DB::table('members')->where('id', $member->id)->update(['agent_id' => $agent->id]);
                }
            }
        }

        Schema::table('members', function (Blueprint $table) {
            $table->foreign('agent_id')->references('id')->on('agents')->nullOnDelete();
        });

        DB::table('members')
            ->whereNotNull('agent_id')
            ->update(['registered_via' => 'agent']);

        if (Schema::hasColumn('members', 'joined_at')) {
            DB::table('members')
                ->whereNull('registered_at')
                ->update(['registered_at' => DB::raw('joined_at')]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->foreign('agent_id')->references('id')->on('users')->nullOnDelete();
            $table->dropColumn(['registered_via', 'referral_code_used', 'registered_at']);
        });
    }
};
