<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Claim;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ClaimItem>
 */
class ClaimItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = $this->faker->randomFloat(2, 10, 500);
        $quantity = $this->faker->numberBetween(1, 5);
        
        return [
            'claim_id' => Claim::factory(),
            'name' => $this->faker->randomElement(['Consultation', 'X-Ray', 'Blood Test', 'Therapy Session', 'Surgery']),
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'subtotal' => $unitPrice * $quantity,
        ];
    }
}
