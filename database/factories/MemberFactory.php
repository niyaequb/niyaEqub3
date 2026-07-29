<?php

namespace Database\Factories;

use App\Enums\RegisteredVia;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Member>
 */
class MemberFactory extends Factory
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
                'type' => 'member',
                'name' => $this->faker->name(),
            ]),
            'full_name' => $this->faker->name(),
            'gender' => $this->faker->optional()->randomElement(['male', 'female']),
            'date_of_birth' => $this->faker->optional()->date(),
            'address' => $this->faker->optional()->address(),
            'agent_id' => null,
            'registered_via' => RegisteredVia::Direct,
            'referral_code_used' => null,
            'registered_at' => now(),
        ];
    }
}
