<?php

namespace Database\Factories;

use App\Enums\CommissionTrigger;
use App\Enums\CommissionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CommissionRule>
 */
class CommissionRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'trigger' => $this->faker->randomElement([
                CommissionTrigger::Signup,
                CommissionTrigger::FirstPayment,
                CommissionTrigger::Payment,
            ]),
            'commission_type' => $this->faker->randomElement([
                CommissionType::Fixed,
                CommissionType::Percentage,
            ]),
            'commission_value' => $this->faker->randomFloat(2, 5, 50),
            'agent_id' => null,
            'is_active' => true,
        ];
    }
}
