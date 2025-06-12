<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Actions\Claims\CreateClaimAction;
use App\Models\Insurer;
use App\Models\Batch;

class CreateTestBatch extends Command
{
    protected $signature = 'claims:create-test-batch 
                            {--provider=Test Medical Center : Provider name}
                            {--insurer= : Insurer code}
                            {--count=5 : Number of claims to create}
                            {--date= : Encounter date (YYYY-MM-DD)}';

    protected $description = 'Create multiple test claims in the same batch';

    public function handle(CreateClaimAction $createClaimAction)
    {
        $providerName = $this->option('provider');
        $claimCount = (int) $this->option('count');
        $encounterDate = $this->option('date') ?? now()->format('Y-m-d');
        
        // Get insurer
        $insurerCode = $this->option('insurer');
        if (!$insurerCode) {
            $insurer = Insurer::active()->first();
            if (!$insurer) {
                $this->error('No active insurers found. Please create an insurer first.');
                return 1;
            }
            $insurerCode = $insurer->code;
        } else {
            $insurer = Insurer::active()->byCode($insurerCode)->first();
            if (!$insurer) {
                $this->error("Insurer '{$insurerCode}' not found or inactive.");
                return 1;
            }
        }

        $this->info("Creating {$claimCount} claims for:");
        $this->line("  Provider: {$providerName}");
        $this->line("  Insurer: {$insurer->code} ({$insurer->name})");
        $this->line("  Date: {$encounterDate}");
        $this->line("  Batch limits: min={$insurer->min_batch_size}, max={$insurer->max_batch_size}");
        $this->newLine();

        $claims = [];
        $services = [
            ['name' => 'Office Visit', 'unit_price' => 150.00],
            ['name' => 'Lab Test', 'unit_price' => 75.00],
            ['name' => 'X-Ray', 'unit_price' => 200.00],
            ['name' => 'EKG', 'unit_price' => 125.00],
            ['name' => 'Blood Work', 'unit_price' => 95.00],
            ['name' => 'Consultation', 'unit_price' => 180.00],
            ['name' => 'Physical Therapy', 'unit_price' => 80.00],
            ['name' => 'Ultrasound', 'unit_price' => 250.00],
        ];

        $specialties = ['General Practice', 'Cardiology', 'Radiology', 'Internal Medicine'];

        $progressBar = $this->output->createProgressBar($claimCount);
        $progressBar->start();

        for ($i = 0; $i < $claimCount; $i++) {
            $service = $services[$i % count($services)];
            $specialty = $specialties[$i % count($specialties)];
            
            $claimData = [
                'provider_name' => $providerName,
                'insurer_code' => $insurerCode,
                'encounter_date' => $encounterDate,
                'specialty' => $specialty,
                'priority_level' => rand(1, 5),
                'items' => [
                    [
                        'name' => $service['name'] . ' #' . ($i + 1),
                        'unit_price' => $service['unit_price'],
                        'quantity' => 1
                    ]
                ]
            ];

            try {
                $claim = $createClaimAction->execute($claimData);
                $claims[] = $claim;
                $progressBar->advance();
            } catch (\Exception $e) {
                $progressBar->finish();
                $this->newLine();
                $this->error("Failed to create claim #" . ($i + 1) . ": " . $e->getMessage());
                return 1;
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // Show results
        $batches = Batch::whereIn('id', collect($claims)->pluck('batch_id')->unique())->get();
        
        $this->info("Created {$claimCount} claims in " . $batches->count() . " batch(es):");
        
        foreach ($batches as $batch) {
            $batchClaims = collect($claims)->where('batch_id', $batch->id);
            $this->line("  Batch #{$batch->id}: {$batchClaims->count()} claims, $" . number_format($batch->total_amount, 2));
        }

        $this->newLine();
        $this->info("You can now process batches with: php artisan batch:process");
        
        return 0;
    }
} 