<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Claim;
use App\Models\Insurer;
use App\Models\Batch;
use App\Models\ClaimItem;
use Carbon\Carbon;

class MultiProviderBatchingTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $insurer1;
    protected $insurer2;
    protected $insurer3;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create multiple insurers with different constraints
        $this->insurer1 = Insurer::factory()->create([
            'code' => 'INS_A',
            'name' => 'Insurer Alpha',
            'daily_capacity' => 50,
            'min_batch_size' => 2,
            'max_batch_size' => 10,
            'processing_cost_per_claim' => 5.00,
            'processing_cost_per_batch' => 25.00,
        ]);

        $this->insurer2 = Insurer::factory()->create([
            'code' => 'INS_B',
            'name' => 'Insurer Beta',
            'daily_capacity' => 100,
            'min_batch_size' => 5,
            'max_batch_size' => 20,
            'processing_cost_per_claim' => 3.00,
            'processing_cost_per_batch' => 15.00,
        ]);

        $this->insurer3 = Insurer::factory()->create([
            'code' => 'INS_C',
            'name' => 'Insurer Gamma',
            'daily_capacity' => 25,
            'min_batch_size' => 3,
            'max_batch_size' => 8,
            'processing_cost_per_claim' => 7.00,
            'processing_cost_per_batch' => 35.00,
        ]);
    }

    /** @test */
    public function it_can_batch_multiple_providers_to_same_insurer()
    {
        $encounterDate = '2024-06-01';
        $providers = [
            'Dr. Alice Medical',
            'Dr. Bob Clinic',
            'Dr. Carol Practice',
            'Dr. David Healthcare'
        ];

        $submittedClaims = [];

        // Submit claims from different providers to the same insurer
        foreach ($providers as $index => $provider) {
            $claimData = [
                'provider_name' => $provider,
                'insurer_code' => $this->insurer1->code,
                'encounter_date' => $encounterDate,
                'specialty' => 'General Practice',
                'items' => [
                    [
                        'name' => "Service for {$provider}",
                        'unit_price' => 100.00 + ($index * 25), // Varying amounts
                        'quantity' => 1,
                    ]
                ]
            ];

            $response = $this->postJson('/api/v1/claims', $claimData);
            $response->assertStatus(201);
            
            $submittedClaims[] = $response->json('data.claim');
        }

        // Verify all claims are in the same batch
        $claims = Claim::where('insurer_code', $this->insurer1->code)->get();
        $this->assertEquals(4, $claims->count());

        $batchIds = $claims->pluck('batch_id')->unique();
        $this->assertEquals(1, $batchIds->count(), 'All claims should be in the same batch');

        // Verify batch contains claims from all providers
        $batch = Batch::find($batchIds->first());
        $this->assertEquals(4, $batch->total_claims);
        
        $providerNames = $batch->claims->pluck('provider_name')->unique();
        $this->assertEquals(4, $providerNames->count());
        
        foreach ($providers as $provider) {
            $this->assertTrue($providerNames->contains($provider));
        }
    }

    /** @test */
    public function it_maintains_separate_batches_for_different_insurers()
    {
        $encounterDate = '2024-06-01';
        $provider = 'Dr. Multi-Insurer Practice';

        // Submit claims to different insurers
        $insurers = [$this->insurer1, $this->insurer2, $this->insurer3];
        
        foreach ($insurers as $index => $insurer) {
            $claimData = [
                'provider_name' => $provider,
                'insurer_code' => $insurer->code,
                'encounter_date' => $encounterDate,
                'specialty' => 'General Practice',
                'items' => [
                    [
                        'name' => "Service {$index}",
                        'unit_price' => 150.00,
                        'quantity' => 1,
                    ]
                ]
            ];

            $response = $this->postJson('/api/v1/claims', $claimData);
            $response->assertStatus(201);
        }

        // Verify we have three separate batches
        $batches = Batch::all();
        $this->assertEquals(3, $batches->count());

        // Verify each batch has correct insurer
        foreach ($insurers as $insurer) {
            $batch = Batch::where('insurer_code', $insurer->code)->first();
            $this->assertNotNull($batch);
            $this->assertEquals(1, $batch->total_claims);
            $this->assertEquals($insurer->code, $batch->insurer_code);
        }
    }

    /** @test */
    public function it_respects_max_batch_size_across_multiple_providers()
    {
        $encounterDate = '2024-06-01';
        
        // Create claims exceeding max batch size
        $claimsToCreate = $this->insurer1->max_batch_size + 3; // 13 claims (max is 10)
        
        for ($i = 0; $i < $claimsToCreate; $i++) {
            $claimData = [
                'provider_name' => "Provider_{$i}",
                'insurer_code' => $this->insurer1->code,
                'encounter_date' => $encounterDate,
                'specialty' => 'General Practice',
                'items' => [
                    [
                        'name' => "Service_{$i}",
                        'unit_price' => 100.00,
                        'quantity' => 1,
                    ]
                ]
            ];

            $response = $this->postJson('/api/v1/claims', $claimData);
            $response->assertStatus(201);
        }

        // Should create multiple batches
        $batches = Batch::where('insurer_code', $this->insurer1->code)->get();
        $this->assertGreaterThan(1, $batches->count());

        // Verify no batch exceeds max size
        foreach ($batches as $batch) {
            $this->assertLessThanOrEqual($this->insurer1->max_batch_size, $batch->total_claims);
        }

        // Verify all claims are accounted for
        $totalClaimsInBatches = $batches->sum('total_claims');
        $this->assertEquals($claimsToCreate, $totalClaimsInBatches);
    }

    /** @test */
    public function it_optimizes_batch_cost_efficiency_across_providers()
    {
        $encounterDate = '2024-06-01';
        
        // Create claims with varying values from different providers
        $claimData = [
            ['provider' => 'High Value Clinic', 'amount' => 5000.00],
            ['provider' => 'Medium Value Practice', 'amount' => 1500.00],
            ['provider' => 'Low Value Office', 'amount' => 500.00],
            ['provider' => 'Standard Care Center', 'amount' => 800.00],
        ];

        foreach ($claimData as $data) {
            $requestData = [
                'provider_name' => $data['provider'],
                'insurer_code' => $this->insurer1->code,
                'encounter_date' => $encounterDate,
                'specialty' => 'General Practice',
                'items' => [
                    [
                        'name' => 'Medical Service',
                        'unit_price' => $data['amount'],
                        'quantity' => 1,
                    ]
                ]
            ];

            $response = $this->postJson('/api/v1/claims', $requestData);
            $response->assertStatus(201);
        }

        // Verify batching occurred
        $batch = Batch::where('insurer_code', $this->insurer1->code)->first();
        $this->assertNotNull($batch);
        $this->assertEquals(4, $batch->total_claims);
        
        // Verify total amount calculation across providers
        $expectedTotal = array_sum(array_column($claimData, 'amount'));
        $this->assertEquals($expectedTotal, $batch->total_amount);

        // Verify cost efficiency
        $perClaimBatchCost = $batch->processing_cost / $batch->total_claims;
        $individualProcessingCost = $this->insurer1->processing_cost_per_batch + $this->insurer1->processing_cost_per_claim;
        
        $this->assertLessThan($individualProcessingCost, $perClaimBatchCost);
    }

    /** @test */
    public function it_handles_mixed_specialties_in_multi_provider_batches()
    {
        $encounterDate = '2024-06-01';
        $specialtyMix = [
            'Cardiology',
            'Dermatology', 
            'General Practice',
            'Orthopedics'
        ];

        foreach ($specialtyMix as $index => $specialty) {
            $claimData = [
                'provider_name' => "Dr. Specialist_{$index}",
                'insurer_code' => $this->insurer2->code,
                'encounter_date' => $encounterDate,
                'specialty' => $specialty,
                'items' => [
                    [
                        'name' => "{$specialty} Service",
                        'unit_price' => 200.00 + ($index * 50),
                        'quantity' => 1,
                    ]
                ]
            ];

            $response = $this->postJson('/api/v1/claims', $claimData);
            $response->assertStatus(201);
        }

        // Verify all claims are batched together despite different specialties
        $batch = Batch::where('insurer_code', $this->insurer2->code)->first();
        $this->assertEquals(4, $batch->total_claims);

        // Verify specialty diversity in batch
        $batchSpecialties = $batch->claims->pluck('specialty')->unique();
        $this->assertEquals(4, $batchSpecialties->count());
        
        foreach ($specialtyMix as $specialty) {
            $this->assertTrue($batchSpecialties->contains($specialty));
        }
    }

    /** @test */
    public function it_handles_capacity_constraints_across_multiple_providers()
    {
        $encounterDate = '2024-06-01';
        
        // Use insurer with low daily capacity
        $lowCapacityInsurer = $this->insurer3; // 25 daily capacity
        
        // Try to submit more claims than daily capacity allows
        $claimsToSubmit = $lowCapacityInsurer->daily_capacity + 10; // 35 claims
        
        for ($i = 0; $i < $claimsToSubmit; $i++) {
            $claimData = [
                'provider_name' => "Provider_{$i}",
                'insurer_code' => $lowCapacityInsurer->code,
                'encounter_date' => $encounterDate,
                'specialty' => 'General Practice',
                'items' => [
                    [
                        'name' => "Service_{$i}",
                        'unit_price' => 100.00,
                        'quantity' => 1,
                    ]
                ]
            ];

            $response = $this->postJson('/api/v1/claims', $claimData);
            $response->assertStatus(201);
        }

        // Verify claims are distributed across multiple days due to capacity constraints
        $batches = Batch::where('insurer_code', $lowCapacityInsurer->code)->get();
        $this->assertGreaterThan(1, $batches->count());

        // Verify daily capacity is not exceeded for any single day
        $batchesByDate = $batches->groupBy('batch_date');
        
        foreach ($batchesByDate as $date => $dateBatches) {
            $dailyClaimCount = $dateBatches->sum('total_claims');
            $this->assertLessThanOrEqual(
                $lowCapacityInsurer->daily_capacity, 
                $dailyClaimCount,
                "Daily capacity exceeded on {$date}"
            );
        }
    }

    /** @test */
    public function it_provides_accurate_analytics_for_multi_provider_batches()
    {
        $encounterDate = '2024-06-01';
        
        // Create diverse set of claims
        $claimConfigs = [
            ['provider' => 'Provider_A', 'insurer' => $this->insurer1, 'amount' => 100],
            ['provider' => 'Provider_B', 'insurer' => $this->insurer1, 'amount' => 200],
            ['provider' => 'Provider_C', 'insurer' => $this->insurer1, 'amount' => 150],
            ['provider' => 'Provider_D', 'insurer' => $this->insurer2, 'amount' => 300],
            ['provider' => 'Provider_E', 'insurer' => $this->insurer2, 'amount' => 250],
        ];

        foreach ($claimConfigs as $config) {
            $claimData = [
                'provider_name' => $config['provider'],
                'insurer_code' => $config['insurer']->code,
                'encounter_date' => $encounterDate,
                'specialty' => 'General Practice',
                'items' => [
                    [
                        'name' => 'Service',
                        'unit_price' => $config['amount'],
                        'quantity' => 1,
                    ]
                ]
            ];

            $this->postJson('/api/v1/claims', $claimData)->assertStatus(201);
        }

        // Get analytics
        $response = $this->getJson('/api/v1/analytics');
        $response->assertStatus(200);

        $analytics = $response->json('data');
        
        // Verify counts
        $this->assertEquals(5, $analytics['total_claims']);
        $this->assertEquals(2, $analytics['total_batches']); // One per insurer
        
        // Verify savings calculation accounts for multi-provider efficiency
        $this->assertGreaterThan(0, $analytics['total_savings']);
        
        // Verify average batch size reflects multi-provider batching
        $this->assertGreaterThan(1, $analytics['average_batch_size']);
    }

    /** @test */
    public function it_maintains_provider_specific_data_in_batches()
    {
        $encounterDate = '2024-06-01';
        
        // Submit claims from multiple providers with same encounter date but different provider details
        $claimConfigs = [
            ['provider' => 'Dr. Early Bird', 'date' => '2024-06-01'],
            ['provider' => 'Dr. Standard Time', 'date' => '2024-06-01'],
            ['provider' => 'Dr. Late Bloomer', 'date' => '2024-06-01'],
        ];

        foreach ($claimConfigs as $config) {
            $claimData = [
                'provider_name' => $config['provider'],
                'insurer_code' => $this->insurer1->code,
                'encounter_date' => $config['date'],
                'specialty' => 'General Practice',
                'items' => [
                    [
                        'name' => 'Consultation',
                        'unit_price' => 175.00,
                        'quantity' => 1,
                    ]
                ]
            ];

            $response = $this->postJson('/api/v1/claims', $claimData);
            $response->assertStatus(201);
        }

        // Verify all claims are in same batch
        $batch = Batch::where('insurer_code', $this->insurer1->code)->first();
        $this->assertEquals(3, $batch->total_claims);

        // Verify provider-specific data is preserved in the multi-provider batch
        $claims = $batch->claims;
        
        foreach ($claimConfigs as $config) {
            $claim = $claims->where('provider_name', $config['provider'])->first();
            $this->assertNotNull($claim, "Claim from {$config['provider']} should exist in batch");
            $this->assertEquals($config['date'], $claim->encounter_date->format('Y-m-d'));
            $this->assertEquals($config['provider'], $claim->provider_name);
        }
        
        // Verify all three providers are represented in the single batch
        $providerNames = $claims->pluck('provider_name')->unique();
        $this->assertEquals(3, $providerNames->count(), 'All three providers should be in the same batch');
    }
} 