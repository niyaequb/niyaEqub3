<?php

namespace Database\Factories;

use App\Enums\PayoutStatus;
use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AgentPayout>
 */
class AgentPayoutFactory extends Factory
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
            'total_amount' => $this->faker->randomFloat(2, 50, 500),
            'status' => PayoutStatus::Pending,
            'paid_at' => null,
            'note' => $this->faker->optional()->sentence(),
        ];
    }
}
