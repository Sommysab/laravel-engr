<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Actions\Claims\CreateClaimAction;
use App\Actions\Claims\ProcessBatchAction;
use App\Models\Claim;
use App\Models\Batch;
use App\Models\Insurer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ClaimController extends Controller
{
    public function __construct(
        private CreateClaimAction $createClaimAction,
        private ProcessBatchAction $processBatchAction
    ) {}

    /**
     * Display a listing of claims with filtering and pagination
     */
    public function index(Request $request): JsonResponse
    {
        $query = Claim::with(['items', 'batch', 'insurer']);

        Log::info('TESTING...........');
        
        // Apply filters based on query parameters
        if ($request->has('provider_name')) {
            $query->where('provider_name', 'like', '%' . $request->provider_name . '%');
        }
        
        if ($request->has('insurer_code')) {
            $query->where('insurer_code', $request->insurer_code);
        }
        
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('specialty')) {
            $query->where('specialty', $request->specialty);
        }
        
        if ($request->has('priority_level')) {
            $query->where('priority_level', $request->priority_level);
        }
        
        if ($request->has('date_from')) {
            $query->whereDate('encounter_date', '>=', $request->date_from);
        }
        
        if ($request->has('date_to')) {
            $query->whereDate('encounter_date', '<=', $request->date_to);
        }
        
        // Apply sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);
        
        // Pagination
        $perPage = min($request->get('per_page', 10), 100); // Max 100 items per page
        $claims = $query->paginate($perPage);
        
        // Format the response
        $formattedClaims = collect($claims->items())->map(function ($claim) {
            return $this->formatClaimResponse($claim);
        });
        
        return response()->json([
            'success' => true,
            'data' => $formattedClaims,
            'pagination' => [
                'current_page' => $claims->currentPage(),
                'per_page' => $claims->perPage(),
                'total' => $claims->total(),
                'last_page' => $claims->lastPage(),
                'from' => $claims->firstItem(),
                'to' => $claims->lastItem(),
            ],
            'filters_applied' => $request->only([
                'provider_name', 'insurer_code', 'status', 'specialty', 
                'priority_level', 'date_from', 'date_to', 'sort_by', 'sort_order'
            ])
        ]);
    }

    /**
     * Submit a new claim from Vue frontend
     */
    public function store(Request $request): JsonResponse
    {
        $validator = $this->validateClaimData($request);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $claim = $this->createClaimAction->execute($validator->validated());

            // Calculate cost savings achieved through optimal batching
            $costSavings = $this->calculateCostSavings($claim);

            return response()->json([
                'success' => true,
                'message' => 'Claim submitted and optimally batched',
                'data' => [
                    'claim' => $this->formatClaimResponse($claim),
                    'batch_info' => $this->formatBatchInfo($claim->batch),
                    'cost_optimization' => $costSavings
                ]
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);

        } catch (\Exception $e) {
            Log::error('Failed to create claim', [
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process claim. Please try again.'
            ], 500);
        }
    }

    /**
     * Get available insurers for frontend dropdown
     */
    public function insurers(): JsonResponse
    {
        $insurers = Insurer::active()
            ->orderBy('name')
            ->get(['code', 'name', 'min_batch_size', 'max_batch_size', 'specialty_multipliers']);

        return response()->json([
            'success' => true,
            'data' => $insurers
        ]);
    }

    /**
     * Get claim details
     */
    public function show(Claim $claim): JsonResponse
    {
        $claim->load(['items', 'batch', 'insurer']);

        return response()->json([
            'success' => true,
            'data' => $this->formatClaimResponse($claim)
        ]);
    }

    /**
     * Get all batches with filtering and pagination
     */
    public function getBatches(Request $request): JsonResponse
    {
        $query = Batch::with(['claims', 'insurer']);
        
        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('insurer_code')) {
            $query->where('insurer_code', $request->insurer_code);
        }
        
        if ($request->has('provider_name')) {
            $query->byProvider($request->provider_name);
        }
        
        // Apply sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);
        
        // Pagination
        $perPage = min($request->get('per_page', 15), 100);
        $batches = $query->paginate($perPage);
        
        return response()->json([
            'success' => true,
            'data' => $batches->items(),
            'pagination' => [
                'current_page' => $batches->currentPage(),
                'per_page' => $batches->perPage(),
                'total' => $batches->total(),
                'last_page' => $batches->lastPage(),
                'from' => $batches->firstItem(),
                'to' => $batches->lastItem(),
            ]
        ]);
    }

    /**
     * Show a specific batch with multi-provider details
     */
    public function showBatch(Batch $batch): JsonResponse
    {
        $batch->load(['claims', 'insurer']);

        return response()->json([
            'success' => true,
            'data' => $batch->toApiResponse()
        ]);
    }

    /**
     * Trigger batch processing for ready batches (only those meeting minimum requirements)
     */
    public function processBatches(): JsonResponse
    {
        try {
            $processedCount = $this->processBatchAction->processReadyBatches();

            return response()->json([
                'success' => true,
                'message' => "Processed {$processedCount} batches successfully",
                'data' => ['processed_count' => $processedCount]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process batches', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process batches'
            ], 500);
        }
    }



    /**
     * Manually mark a batch as completed
     */
    public function completeBatch(Batch $batch): JsonResponse
    {
        try {
            // Ensure batch is in processing status
            if ($batch->status !== 'processing') {
                return response()->json([
                    'success' => false,
                    'message' => "Batch must be in 'processing' status to be completed. Current status: {$batch->status}"
                ], 400);
            }

            // Mark batch as completed
            $batch->markAsCompleted();

            // Update all claims in this batch to completed status
            $batch->claims()->update([
                'status' => 'completed',
                'processed_at' => now()
            ]);

            Log::info('Batch manually completed', [
                'batch_id' => $batch->id,
                'claims_completed' => $batch->total_claims,
                'total_amount' => $batch->total_amount
            ]);

            return response()->json([
                'success' => true,
                'message' => "Batch #{$batch->id} marked as completed successfully",
                'data' => [
                    'batch_id' => $batch->id,
                    'status' => 'completed',
                    'completed_at' => $batch->completed_at,
                    'claims_completed' => $batch->total_claims
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to complete batch', [
                'batch_id' => $batch->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to complete batch'
            ], 500);
        }
    }

    /**
     * Get optimization analytics
     */
    public function analytics(): JsonResponse
    {
        $analytics = [
            'total_claims' => Claim::count(),
            'pending_claims' => Claim::pending()->count(),
            'total_batches' => Batch::count(),
            'pending_batches' => Batch::pending()->count(),
            'total_savings' => $this->calculateTotalSavings(),
            'average_batch_size' => Batch::avg('total_claims'),
            'cost_efficiency' => $this->calculateCostEfficiency()
        ];

        return response()->json([
            'success' => true,
            'data' => $analytics
        ]);
    }

    public function costAnalysis()
    {
        $stats = [
            'total_savings' => $this->calculateTotalSavings(),
            'average_batch_size' => Batch::where('status', '!=', 'pending')->avg('total_claims'),
            'cost_efficiency_ratio' => $this->calculateEfficiencyRatio(),
        ];

        return response()->json($stats);
    }

    /**
     * Get detailed cost breakdown for a specific claim
     */
    public function getCostBreakdown(Claim $claim)
    {
        $insurer = $claim->insurer;
        if (!$insurer) {
            return response()->json(['error' => 'Insurer not found for claim'], 404);
        }

        // Get cost breakdown for different scenarios
        $breakdown = [
            'current_date' => $insurer->getCostBreakdown($claim),
            'first_of_month' => $insurer->getCostBreakdown($claim, now()->startOfMonth()),
            'end_of_month' => $insurer->getCostBreakdown($claim, now()->endOfMonth()),
            'batch_date' => $claim->batch ? $insurer->getCostBreakdown($claim, $claim->batch->batch_date) : null
        ];

        return response()->json([
            'claim_id' => $claim->id,
            'insurer' => $insurer->code,
            'cost_breakdown' => $breakdown,
            'notes' => [
                'time_multiplier' => 'Processing costs increase linearly from 20% on day 1 to 50% on day 30',
                'value_multiplier' => 'Higher value claims require more processing effort',
                'specialty_multiplier' => 'Some insurers are more efficient at certain specialties',
                'priority_multiplier' => 'Higher priority claims get expedited processing'
            ]
        ]);
    }

    private function validateClaimData(Request $request)
    {
        return Validator::make($request->all(), [
            'provider_name' => 'required|string|max:255',
            'insurer_code' => 'required|string|exists:insurers,code',
            'encounter_date' => 'required|date',
            'submission_date' => 'nullable|date',
            'specialty' => 'required|string|max:100',
            'priority_level' => 'nullable|integer|min:1|max:5',
            
            // Multi-item support
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:255',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
        ]);
    }

    private function formatClaimResponse(Claim $claim): array
    {
        return [
            'id' => $claim->id,
            'provider_name' => $claim->provider_name,
            'insurer_code' => $claim->insurer_code,
            'insurer_name' => $claim->insurer->name ?? null,
            'encounter_date' => $claim->encounter_date->format('Y-m-d'),
            'submission_date' => $claim->submission_date->format('Y-m-d'),
            'specialty' => $claim->specialty,
            'priority_level' => $claim->priority_level,
            'total_amount' => (float) $claim->total_amount,
            'status' => $claim->status,
            'items' => $claim->items->map(fn($item) => [
                'name' => $item->name,
                'unit_price' => (float) $item->unit_price,
                'quantity' => $item->quantity,
                'subtotal' => (float) $item->subtotal,
            ]),
            'batch_id' => $claim->batch_id,
            'created_at' => $claim->created_at->toISOString(),
        ];
    }

    private function formatBatchInfo(?Batch $batch): ?array
    {
        if (!$batch) {
            return null;
        }

        return [
            'id' => $batch->id,
            'insurer_code' => $batch->insurer_code,
            'batch_date' => $batch->batch_date->format('Y-m-d'),
            'total_claims' => $batch->total_claims,
            'total_amount' => (float) $batch->total_amount,
            'processing_cost' => (float) $batch->processing_cost,
            'status' => $batch->status,
            'provider_count' => $batch->provider_count,
            'providers' => $batch->getProviders(),
            'optimality_score' => round($batch->getOptimalityScore(), 2),
        ];
    }

    private function calculateCostSavings(Claim $claim): array
    {
        $batch = $claim->batch;
        if (!$batch || !$batch->insurer) {
            return ['savings' => 0, 'efficiency' => 0];
        }

        // Calculate cost if processed individually vs in batch
        $individualCost = $batch->insurer->calculateBatchProcessingCost(1, $claim->total_amount);
        $actualCostPerClaim = $batch->processing_cost / max($batch->total_claims, 1);
        $savings = max(0, $individualCost - $actualCostPerClaim);

        return [
            'individual_cost' => round($individualCost, 2),
            'batched_cost' => round($actualCostPerClaim, 2),
            'savings' => round($savings, 2),
            'efficiency_percentage' => round(($savings / $individualCost) * 100, 1)
        ];
    }

    private function calculateTotalSavings(): float
    {
        // Calculate savings by comparing batch processing vs individual processing
        $totalSavings = 0;
        
        $batches = Batch::with('claims')->get();
        
        foreach ($batches as $batch) {
            if ($batch->claims->isEmpty() || !$batch->insurer) {
                continue;
            }
            
            // Calculate what it would cost to process each claim individually
            $individualCosts = 0;
            foreach ($batch->claims as $claim) {
                $individualCosts += $batch->insurer->processing_cost_per_batch + 
                                  $batch->insurer->getProcessingCostForClaim($claim, $batch->batch_date);
            }
            
            // Actual batch cost
            $batchCost = $batch->processing_cost;
            
            // Savings is the difference
            $totalSavings += max(0, $individualCosts - $batchCost);
        }
        
        return round($totalSavings, 2);
    }

    private function calculateCostEfficiency(): float
    {
        $batches = Batch::where('status', '!=', 'pending')->get();
        if ($batches->isEmpty()) {
            return 0;
        }

        return $batches->avg(function ($batch) {
            return $batch->getOptimalityScore();
        });
    }

    private function calculateEfficiencyRatio(): float
    {
        $batches = Batch::where('status', '!=', 'pending')->get();
        if ($batches->isEmpty()) {
            return 0;
        }

        $totalSavings = $this->calculateTotalSavings();
        $totalCost = $batches->sum('processing_cost');
        return $totalSavings / $totalCost;
    }
}
