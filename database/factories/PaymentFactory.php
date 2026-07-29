<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'amount' => $this->faker->randomFloat(2, 10, 300),
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ];
    }
}
