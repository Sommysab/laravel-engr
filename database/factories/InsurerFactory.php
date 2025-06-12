<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Insurer>
 */
class InsurerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company() . ' Insurance',
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'daily_capacity' => $this->faker->numberBetween(500, 3000),
            'min_batch_size' => $this->faker->numberBetween(1, 5),
            'max_batch_size' => $this->faker->numberBetween(20, 100),
            'processing_cost_per_claim' => $this->faker->randomFloat(2, 2.50, 10.00),
            'processing_cost_per_batch' => $this->faker->randomFloat(2, 10.00, 50.00),
            'date_preference' => $this->faker->randomElement(['submission_date', 'encounter_date']),
            'specialty_multipliers' => [
                'Cardiology' => $this->faker->randomFloat(1, 1.0, 1.5),
                'Surgery' => $this->faker->randomFloat(1, 1.2, 1.8),
                'General Practice' => 1.0,
            ],
            'email' => $this->faker->companyEmail(),
            'is_active' => true,
        ];
    }
}
