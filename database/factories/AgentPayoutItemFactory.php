<?php

namespace Database\Factories;

use App\Models\AgentCommission;
use App\Models\AgentPayout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AgentPayoutItem>
 */
class AgentPayoutItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_payout_id' => AgentPayout::factory(),
            'agent_commission_id' => AgentCommission::factory(),
        ];
    }
}
