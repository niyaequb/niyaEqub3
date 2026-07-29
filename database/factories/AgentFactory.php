<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Agent>
 */
class AgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state([
                'type' => 'agent',
                'name' => $this->faker->name(),
            ]),
            'referral_code' => strtoupper($this->faker->unique()->bothify('AGT###??')),
            'commission_rule_id' => null,
            'is_active' => true,
            'joined_at' => now(),
        ];
    }
}
