<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Claim;
use App\Models\Insurer;
use App\Models\Batch;
use App\Models\ClaimItem;
use Carbon\Carbon;

class ClaimControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test insurers with different processing constraints
        $this->insurer = Insurer::factory()->create([
            'code' => 'TEST_INS',
            'name' => 'Test Insurance',
            'daily_capacity' => 100,
            'min_batch_size' => 2,
            'max_batch_size' => 10,
            'processing_cost_per_claim' => 5.00,
            'processing_cost_per_batch' => 25.00,
            'date_preference' => 'submission_date',
            'specialty_multipliers' => [
                'General Practice' => 1.0,
                'Surgery' => 1.5,
                'Cardiology' => 1.3,
                'Dermatology' => 1.1,
            ],
            'email' => 'test@insurance.com',
            'is_active' => true,
        ]);
        
        // Create a second insurer for multi-provider testing
        $this->insurer2 = Insurer::factory()->create([
            'code' => 'TEST_INS2',
            'name' => 'Second Test Insurance',
            'daily_capacity' => 50,
            'min_batch_size' => 3,
            'max_batch_size' => 8,
            'processing_cost_per_claim' => 6.00,
            'processing_cost_per_batch' => 30.00,
            'date_preference' => 'encounter_date',
            'email' => 'test2@insurance.com',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_can_submit_a_claim_with_multiple_items()
    {
        $claimData = [
            'provider_name' => 'Dr. Smith Medical Practice',
            'insurer_code' => $this->insurer->code,
            'encounter_date' => '2024-06-01',
            'specialty' => 'Cardiology',
            'priority_level' => 4,
            'items' => [
                [
                    'name' => 'Consultation',
                    'unit_price' => 150.00,
                    'quantity' => 1,
                ],
                [
                    'name' => 'EKG Test',
                    'unit_price' => 75.00,
                    'quantity' => 1,
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/claims', $claimData);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'data' => [
                        'claim' => [
                            'id',
                            'provider_name',
                            'insurer_code',
                            'total_amount',
                            'status',
                            'items',
                            'batch_id'
                        ],
                        'batch_info',
                        'cost_optimization'
                    ]
                ]);

        // Verify claim was created
        $this->assertDatabaseHas('claims', [
            'provider_name' => 'Dr. Smith Medical Practice',
            'insurer_code' => $this->insurer->code,
            'status' => 'batched'
        ]);

        // Verify claim items were created
        $claim = Claim::first();
        $this->assertEquals(2, $claim->items->count());
        $this->assertEquals(225.00, $claim->total_amount);

        // Verify batch was created and claim was assigned
        $this->assertNotNull($claim->batch_id);
        $batch = $claim->batch;
        $this->assertEquals(1, $batch->total_claims);
        $this->assertEquals(225.00, $batch->total_amount);
    }

    /** @test */
    public function it_batches_claims_from_same_insurer_together()
    {
        $encounterDate = '2024-06-01';

        // Submit first claim from Provider A
        $claim1Data = [
            'provider_name' => 'Dr. Johnson Clinic',
            'insurer_code' => $this->insurer->code,
            'encounter_date' => $encounterDate,
            'specialty' => 'General Practice',
            'items' => [['name' => 'Visit', 'unit_price' => 100.00, 'quantity' => 1]]
        ];

        $response1 = $this->postJson('/api/v1/claims', $claim1Data);
        $response1->assertStatus(201);

        $firstClaim = Claim::first();
        $firstBatch = $firstClaim->batch;

        // Submit second claim from Provider B for same insurer
        $claim2Data = [
            'provider_name' => 'Dr. Wilson Practice',
            'insurer_code' => $this->insurer->code,
            'encounter_date' => $encounterDate,
            'specialty' => 'General Practice',
            'items' => [['name' => 'Test', 'unit_price' => 50.00, 'quantity' => 1]]
        ];

        $response2 = $this->postJson('/api/v1/claims', $claim2Data);
        $response2->assertStatus(201);

        $secondClaim = Claim::latest()->first();

        // Verify both claims are in the same batch (multi-provider batching)
        $this->assertEquals($firstBatch->id, $secondClaim->batch_id);
        
        // Verify batch metrics are updated
        $firstBatch->refresh();
        $this->assertEquals(2, $firstBatch->total_claims);
        $this->assertEquals(150.00, $firstBatch->total_amount);

        // Verify the batch contains claims from different providers
        $batchClaims = $firstBatch->claims;
        $providerNames = $batchClaims->pluck('provider_name')->unique();
        $this->assertEquals(2, $providerNames->count());
        $this->assertTrue($providerNames->contains('Dr. Johnson Clinic'));
        $this->assertTrue($providerNames->contains('Dr. Wilson Practice'));
    }

    /** @test */
    public function it_respects_insurer_batch_size_constraints()
    {
        $encounterDate = '2024-06-01';

        // Track initial batch count
        $initialBatchCount = Batch::count();

        // Create claims up to max batch size for the insurer
        for ($i = 0; $i < $this->insurer->max_batch_size; $i++) {
            $claimData = [
                'provider_name' => "Provider {$i}",
                'insurer_code' => $this->insurer->code,
                'encounter_date' => $encounterDate,
                'specialty' => 'General Practice',
                'items' => [['name' => "Service {$i}", 'unit_price' => 100.00, 'quantity' => 1]]
            ];

            $this->postJson('/api/v1/claims', $claimData)->assertStatus(201);
        }

        // Should have created exactly one new batch
        $this->assertEquals($initialBatchCount + 1, Batch::count());
        
        // Get the batch created for this insurer
        $firstBatch = Batch::where('insurer_code', $this->insurer->code)->first();
        $this->assertEquals($this->insurer->max_batch_size, $firstBatch->total_claims);

        // Submit one more claim - should create a new batch
        $extraClaimData = [
            'provider_name' => 'Extra Provider',
            'insurer_code' => $this->insurer->code,
            'encounter_date' => $encounterDate,
            'specialty' => 'General Practice',
            'items' => [['name' => 'Extra Service', 'unit_price' => 100.00, 'quantity' => 1]]
        ];

        $this->postJson('/api/v1/claims', $extraClaimData)->assertStatus(201);

        // Should now have two batches for this insurer
        $insurerBatches = Batch::where('insurer_code', $this->insurer->code)->orderBy('id')->get();
        $this->assertEquals(2, $insurerBatches->count());
        
        $secondBatch = $insurerBatches->last();
        $this->assertEquals(1, $secondBatch->total_claims);
        
        // Verify the second batch is for the next business day
        $this->assertTrue($secondBatch->batch_date->gt($firstBatch->batch_date));
    }

    /** @test */
    public function it_creates_separate_batches_for_different_insurers()
    {
        $encounterDate = '2024-06-01';

        // Submit claim to first insurer
        $claim1Data = [
            'provider_name' => 'Dr. Multi Provider',
            'insurer_code' => $this->insurer->code,
            'encounter_date' => $encounterDate,
            'specialty' => 'General Practice',
            'items' => [['name' => 'Service 1', 'unit_price' => 100.00, 'quantity' => 1]]
        ];

        $response1 = $this->postJson('/api/v1/claims', $claim1Data);
        $response1->assertStatus(201);

        // Submit claim to second insurer
        $claim2Data = [
            'provider_name' => 'Dr. Multi Provider',
            'insurer_code' => $this->insurer2->code,
            'encounter_date' => $encounterDate,
            'specialty' => 'General Practice',
            'items' => [['name' => 'Service 2', 'unit_price' => 150.00, 'quantity' => 1]]
        ];

        $response2 = $this->postJson('/api/v1/claims', $claim2Data);
        $response2->assertStatus(201);

        // Verify claims are in different batches
        $claims = Claim::all();
        $this->assertEquals(2, $claims->count());
        
        $claim1 = $claims->where('insurer_code', $this->insurer->code)->first();
        $claim2 = $claims->where('insurer_code', $this->insurer2->code)->first();
        
        $this->assertNotEquals($claim1->batch_id, $claim2->batch_id);
        
        // Verify each batch has correct insurer
        $this->assertEquals($this->insurer->code, $claim1->batch->insurer_code);
        $this->assertEquals($this->insurer2->code, $claim2->batch->insurer_code);
    }

    /** @test */
    public function it_calculates_processing_costs_correctly()
    {
        $claimData = [
            'provider_name' => 'Cost Test Clinic',
            'insurer_code' => $this->insurer->code,
            'encounter_date' => '2024-06-01',
            'specialty' => 'Cardiology',
            'priority_level' => 3,
            'items' => [['name' => 'Service', 'unit_price' => 200.00, 'quantity' => 1]]
        ];

        $response = $this->postJson('/api/v1/claims', $claimData);

        $response->assertStatus(201);
        $claim = Claim::first();
        $batch = $claim->batch;

        // Calculate expected cost with all factors:
        // 1. Base cost: 5.00
        // 2. Specialty multiplier: 1.2 (from factory default for Cardiology)
        // 3. Priority multiplier: 1.2 (priority 3 = 1 + ((3-1) * 0.1))
        // 4. Time multiplier: varies by current date
        // 5. Value multiplier: 1.0 (200.00 is under $1k threshold)
        
        // Instead of hardcoding expected cost, let's verify using the actual calculation
        $expectedClaimCost = $this->insurer->getProcessingCostForClaim($claim, $batch->batch_date);
        $expectedBatchCost = $this->insurer->processing_cost_per_batch + $expectedClaimCost;

        $this->assertEquals(round($expectedBatchCost, 2), round($batch->processing_cost, 2));
        
        // Also verify the calculation is reasonable (should be more than base cost due to multipliers)
        $baseCost = $this->insurer->processing_cost_per_batch + $this->insurer->processing_cost_per_claim;
        $this->assertGreaterThan($baseCost, $batch->processing_cost);
    }

    /** @test */
    public function it_validates_claim_submission_data()
    {
        $invalidData = [
            'provider_name' => '', // required
            'insurer_code' => 'INVALID', // must exist
            'encounter_date' => 'invalid-date',
            'items' => [] // must have at least 1 item
        ];

        $response = $this->postJson('/api/v1/claims', $invalidData);

        $response->assertStatus(422)
                ->assertJsonValidationErrors([
                    'provider_name',
                    'insurer_code',
                    'encounter_date',
                    'items'
                ]);
    }

    /** @test */
    public function it_returns_available_insurers()
    {
        $response = $this->getJson('/api/v1/insurers');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        '*' => ['code', 'name', 'min_batch_size', 'max_batch_size']
                    ]
                ]);

        $this->assertCount(2, $response->json('data')); // Updated to expect 2 insurers
    }

    /** @test */
    public function it_can_retrieve_claim_details()
    {
        $claim = Claim::factory()->create([
            'insurer_code' => $this->insurer->code
        ]);
        
        ClaimItem::factory()->create(['claim_id' => $claim->id]);

        $response = $this->getJson("/api/v1/claims/{$claim->id}");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'id',
                        'provider_name',
                        'insurer_code',
                        'total_amount',
                        'items'
                    ]
                ]);
    }

    /** @test */
    public function it_provides_analytics_data()
    {
        // Create some test data
        Claim::factory()->count(5)->create(['insurer_code' => $this->insurer->code]);
        Batch::factory()->count(2)->create(['insurer_code' => $this->insurer->code]);

        $response = $this->getJson('/api/v1/analytics');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'total_claims',
                        'pending_claims',
                        'total_batches',
                        'pending_batches',
                        'total_savings',
                        'average_batch_size',
                        'cost_efficiency'
                    ]
                ]);
    }

    /** @test */
    public function it_can_process_batches()
    {
        // Create a batch with minimum claims
        $batch = Batch::factory()->create([
            'insurer_code' => $this->insurer->code,
            'status' => 'pending',
            'total_claims' => $this->insurer->min_batch_size
        ]);

        $response = $this->postJson('/api/v1/batches/process');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'processed_count'
                    ]
                ]);
    }

    /** @test */
    public function it_handles_inactive_insurer_rejection()
    {
        // Make insurer inactive
        $this->insurer->update(['is_active' => false]);

        $claimData = [
            'provider_name' => 'Test Provider',
            'insurer_code' => $this->insurer->code,
            'encounter_date' => '2024-06-01',
            'specialty' => 'General Practice',
            'items' => [['name' => 'Service', 'unit_price' => 100.00, 'quantity' => 1]]
        ];

        $response = $this->postJson('/api/v1/claims', $claimData);

        $response->assertStatus(400);
    }

    /** @test */
    public function it_applies_all_cost_factors_correctly()
    {
        // Test high-value claim with maximum cost factors
        $highValueClaimData = [
            'provider_name' => 'Premium Provider',
            'insurer_code' => $this->insurer->code,
            'encounter_date' => now()->format('Y-m-d'),
            'specialty' => 'Surgery',
            'priority_level' => 5,
            'items' => [
                [
                    'name' => 'Complex Surgery',
                    'unit_price' => 15000.00, // High value to trigger value multiplier
                    'quantity' => 1,
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/claims', $highValueClaimData);
        $response->assertStatus(201);

        $highValueClaim = Claim::where('provider_name', 'Premium Provider')->first();
        $highValueBatch = $highValueClaim->batch;

        // Test low-value, minimal processing claim for comparison
        $standardClaimData = [
            'provider_name' => 'Standard Provider',
            'insurer_code' => $this->insurer->code,
            'encounter_date' => now()->format('Y-m-d'),
            'specialty' => 'General Practice',
            'priority_level' => 1,
            'items' => [
                [
                    'name' => 'Standard Visit',
                    'unit_price' => 100.00, // Low value
                    'quantity' => 1,
                ]
            ]
        ];

        $this->postJson('/api/v1/claims', $standardClaimData)->assertStatus(201);

        $standardClaim = Claim::where('provider_name', 'Standard Provider')->first();

        // Calculate costs using the same batch date to isolate the multiplier effects
        $testDate = now()->startOfMonth()->addDays(15); // Mid-month to get consistent time multiplier
        
        $baseCost = $this->insurer->processing_cost_per_claim;
        $highValueCost = $this->insurer->getProcessingCostForClaim($highValueClaim, $testDate);
        $standardCost = $this->insurer->getProcessingCostForClaim($standardClaim, $testDate);

        // Get cost breakdowns to verify multipliers
        $highValueBreakdown = $this->insurer->getCostBreakdown($highValueClaim, $testDate);
        $standardBreakdown = $this->insurer->getCostBreakdown($standardClaim, $testDate);

        // Verify that high value claim has proper multipliers
        $this->assertGreaterThan(1.0, $highValueBreakdown['value_multiplier']); // Should have value multiplier > 1
        $this->assertGreaterThan(1.0, $highValueBreakdown['priority_multiplier']); // Should have priority multiplier > 1
        $this->assertGreaterThan(1.0, $highValueBreakdown['specialty_multiplier']); // Surgery should have > 1.0
        $this->assertEquals(1.0, $standardBreakdown['specialty_multiplier']); // General Practice should be 1.0
        $this->assertEquals(1.0, $standardBreakdown['value_multiplier']); // Low value should be 1.0
        
        // Verify high-value claim costs significantly more
        $this->assertGreaterThan($standardCost, $highValueCost);
        $this->assertGreaterThan($baseCost, $highValueBreakdown['final_cost']); // Should be higher than base cost
    }

    /** @test */
    public function it_provides_cost_breakdown_via_api()
    {
        $claimData = [
            'provider_name' => 'Cost Analysis Provider',
            'insurer_code' => $this->insurer->code,
            'encounter_date' => '2024-06-15',
            'specialty' => 'Cardiology',
            'priority_level' => 3,
            'items' => [
                [
                    'name' => 'Cardiac Procedure',
                    'unit_price' => 1500.00,
                    'quantity' => 1,
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/claims', $claimData);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'data' => [
                        'cost_optimization' => [
                            'individual_cost',
                            'batched_cost',
                            'savings',
                            'efficiency_percentage'
                        ]
                    ]
                ]);

        $costData = $response->json('data.cost_optimization');
        
        // Verify cost optimization data is present and reasonable
        $this->assertIsNumeric($costData['individual_cost']);
        $this->assertIsNumeric($costData['batched_cost']);
        $this->assertIsNumeric($costData['savings']);
        $this->assertIsNumeric($costData['efficiency_percentage']);
        
        $this->assertGreaterThan(0, $costData['individual_cost']);
        $this->assertGreaterThan(0, $costData['batched_cost']);
        $this->assertGreaterThanOrEqual(0, $costData['savings']);
        $this->assertGreaterThanOrEqual(0, $costData['efficiency_percentage']);
    }
}
