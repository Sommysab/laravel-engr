<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Insurer;

class InsurerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $insurers = [
            [
                'name' => 'Blue Cross Blue Shield',
                'code' => 'BCBS',
                'daily_capacity' => 2000,
                'min_batch_size' => 5,
                'max_batch_size' => 50,
                'processing_cost_per_claim' => 3.50,
                'processing_cost_per_batch' => 15.00,
                'date_preference' => 'submission_date',
                'specialty_multipliers' => [
                    'Cardiology' => 1.2,
                    'Neurology' => 1.3,
                    'Surgery' => 1.5,
                    'General Practice' => 1.0,
                    'Pediatrics' => 0.9,
                    'Dermatology' => 1.1
                ],
                'email' => 'claims@bcbs.com',
                'is_active' => true,
            ],
            [
                'name' => 'Aetna Health Insurance',
                'code' => 'AETNA',
                'daily_capacity' => 1500,
                'min_batch_size' => 3,
                'max_batch_size' => 40,
                'processing_cost_per_claim' => 4.00,
                'processing_cost_per_batch' => 20.00,
                'date_preference' => 'encounter_date',
                'specialty_multipliers' => [
                    'Cardiology' => 1.15,
                    'Neurology' => 1.25,
                    'Surgery' => 1.4,
                    'General Practice' => 1.0,
                    'Pediatrics' => 0.95,
                    'Dermatology' => 1.05
                ],
                'email' => 'processing@aetna.com',
                'is_active' => true,
            ],
            [
                'name' => 'UnitedHealth Group',
                'code' => 'UHG',
                'daily_capacity' => 3000,
                'min_batch_size' => 10,
                'max_batch_size' => 100,
                'processing_cost_per_claim' => 2.75,
                'processing_cost_per_batch' => 12.50,
                'date_preference' => 'submission_date',
                'specialty_multipliers' => [
                    'Cardiology' => 1.1,
                    'Neurology' => 1.2,
                    'Surgery' => 1.35,
                    'General Practice' => 1.0,
                    'Pediatrics' => 0.85,
                    'Dermatology' => 1.0
                ],
                'email' => 'batches@uhg.com',
                'is_active' => true,
            ],
            [
                'name' => 'Humana Inc.',
                'code' => 'HUMANA',
                'daily_capacity' => 1200,
                'min_batch_size' => 2,
                'max_batch_size' => 30,
                'processing_cost_per_claim' => 4.25,
                'processing_cost_per_batch' => 18.00,
                'date_preference' => 'encounter_date',
                'specialty_multipliers' => [
                    'Cardiology' => 1.3,
                    'Neurology' => 1.4,
                    'Surgery' => 1.6,
                    'General Practice' => 1.0,
                    'Pediatrics' => 1.0,
                    'Dermatology' => 1.1
                ],
                'email' => 'claims@humana.com',
                'is_active' => true,
            ],
            [
                'name' => 'Cigna Corporation',
                'code' => 'CIGNA',
                'daily_capacity' => 1800,
                'min_batch_size' => 5,
                'max_batch_size' => 60,
                'processing_cost_per_claim' => 3.75,
                'processing_cost_per_batch' => 16.50,
                'date_preference' => 'submission_date',
                'specialty_multipliers' => [
                    'Cardiology' => 1.25,
                    'Neurology' => 1.35,
                    'Surgery' => 1.45,
                    'General Practice' => 1.0,
                    'Pediatrics' => 0.9,
                    'Dermatology' => 1.05
                ],
                'email' => 'processing@cigna.com',
                'is_active' => true,
            ]
        ];

        foreach ($insurers as $insurerData) {
            Insurer::updateOrCreate(
                ['code' => $insurerData['code']],
                $insurerData
            );
        }
    }
}
