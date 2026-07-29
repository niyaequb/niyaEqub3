<?php

namespace Database\Factories;

use App\Enums\CommissionStatus;
use App\Enums\CommissionTrigger;
use App\Models\Agent;
use App\Models\CommissionRule;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AgentCommission>
 */
class AgentCommissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'member_id' => Member::factory(),
            'commission_rule_id' => CommissionRule::factory(),
            'source' => $this->faker->randomElement([
                CommissionTrigger::Signup,
                CommissionTrigger::FirstPayment,
                CommissionTrigger::Payment,
            ]),
            'reference_id' => null,
            'base_amount' => $this->faker->randomFloat(2, 10, 200),
            'commission_amount' => $this->faker->randomFloat(2, 1, 50),
            'status' => CommissionStatus::Pending,
            'created_at' => now(),
        ];
    }
}
