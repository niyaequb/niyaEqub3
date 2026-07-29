<?php

namespace Database\Seeders;

use App\Enums\CommissionTrigger;
use App\Enums\CommissionType;
use App\Models\CommissionRule;
use Illuminate\Database\Seeder;

class CommissionRuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        CommissionRule::query()->create([
            'name' => 'Signup bonus',
            'trigger' => CommissionTrigger::Signup,
            'commission_type' => CommissionType::Fixed,
            'commission_value' => 25,
            'agent_id' => null,
            'is_active' => true,
        ]);

        CommissionRule::query()->create([
            'name' => 'First payment bonus',
            'trigger' => CommissionTrigger::FirstPayment,
            'commission_type' => CommissionType::Percentage,
            'commission_value' => 5,
            'agent_id' => null,
            'is_active' => true,
        ]);

        CommissionRule::query()->create([
            'name' => 'Recurring payment bonus',
            'trigger' => CommissionTrigger::Payment,
            'commission_type' => CommissionType::Percentage,
            'commission_value' => 2,
            'agent_id' => null,
            'is_active' => true,
        ]);
    }
}
