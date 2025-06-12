<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Insurer;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Claim>
 */
class ClaimFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_name' => $this->faker->company() . ' Medical Center',
            'insurer_code' => Insurer::factory(),
            'encounter_date' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'submission_date' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'specialty' => $this->faker->randomElement(['Cardiology', 'Surgery', 'General Practice', 'Pediatrics']),
            'priority_level' => $this->faker->numberBetween(1, 5),
            'total_amount' => $this->faker->randomFloat(2, 50, 1000),
            'status' => 'pending',
        ];
    }
}
