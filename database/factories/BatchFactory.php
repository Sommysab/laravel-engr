<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Insurer;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Batch>
 */
class BatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'insurer_code' => Insurer::factory(),
            'batch_date' => $this->faker->dateTimeBetween('-7 days', '+7 days'),
            'total_claims' => $this->faker->numberBetween(1, 50),
            'total_amount' => $this->faker->randomFloat(2, 100, 5000),
            'processing_cost' => $this->faker->randomFloat(2, 50, 500),
            'status' => $this->faker->randomElement(['pending', 'processing', 'completed']),
        ];
    }
}
