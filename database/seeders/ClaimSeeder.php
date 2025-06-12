<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Claim;
use App\Models\ClaimItem;
use App\Models\Insurer;

class ClaimSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure we have insurers
        $insurers = Insurer::all();
        if ($insurers->count() == 0) {
            $this->call(InsurerSeeder::class);
            $insurers = Insurer::all();
        }

        $specialties = ['Cardiology', 'Dermatology', 'General Practice', 'Pediatrics', 'Surgery', 'Emergency Medicine'];
        $statuses = ['pending', 'batched', 'processing', 'completed'];
        $itemNames = ['Consultation', 'X-Ray', 'Blood Test', 'MRI Scan', 'Surgery', 'EKG', 'Ultrasound', 'Lab Work'];

        // Create 15 test claims
        for ($i = 1; $i <= 15; $i++) {
            $insurer = $insurers->random();
            
            $claim = Claim::create([
                'provider_name' => 'Test Provider ' . $i,
                'insurer_code' => $insurer->code,
                'encounter_date' => now()->subDays(rand(1, 60)),
                'submission_date' => now()->subDays(rand(0, 7)),
                'specialty' => $specialties[array_rand($specialties)],
                'priority_level' => rand(1, 5),
                'status' => $statuses[array_rand($statuses)],
            ]);
            
            // Add 1-4 items per claim
            $itemCount = rand(1, 4);
            for ($j = 0; $j < $itemCount; $j++) {
                ClaimItem::create([
                    'claim_id' => $claim->id,
                    'name' => $itemNames[array_rand($itemNames)],
                    'unit_price' => rand(25, 750) + (rand(0, 99) / 100), // Random price with cents
                    'quantity' => rand(1, 5),
                ]);
            }
            
            // Update total amount (auto-calculated by model events)
            $claim->updateTotalAmount();
            
            $this->command->info("Created claim {$claim->id} for {$claim->provider_name} with {$itemCount} items");
        }
        
        $this->command->info('Successfully created 15 test claims with items!');
    }
}
