<?php

namespace App\Actions\Claims;

use App\Models\Claim;
use App\Models\ClaimItem;
use App\Models\Insurer;
use App\Models\Batch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CreateClaimAction
{
    public function execute(array $claimData): Claim
    {
        return DB::transaction(function () use ($claimData) {
            // 1. Create the claim
            $claim = $this->createClaim($claimData);
            
            // 2. Create claim items if provided
            if (isset($claimData['items']) && is_array($claimData['items'])) {
                $this->createClaimItems($claim, $claimData['items']);
            }
            
            // 3. Optimize batching immediately
            $this->optimizeBatching($claim);
            
            Log::info('Claim created and optimized', [
                'claim_id' => $claim->id,
                'provider' => $claim->provider_name,
                'insurer' => $claim->insurer_code,
                'amount' => $claim->total_amount
            ]);
            
            return $claim->fresh(['items', 'batch', 'insurer']);
        });
    }

    private function createClaim(array $claimData): Claim
    {
        // Validate insurer exists and is active
        $insurer = Insurer::active()->byCode($claimData['insurer_code'])->first();
        if (!$insurer) {
            throw new \InvalidArgumentException("Invalid or inactive insurer: {$claimData['insurer_code']}");
        }

        return Claim::create([
            'provider_name' => $claimData['provider_name'],
            'insurer_code' => $claimData['insurer_code'],
            'encounter_date' => Carbon::parse($claimData['encounter_date']),
            'submission_date' => $claimData['submission_date'] ?? now(),
            'specialty' => $claimData['specialty'],
            'priority_level' => $claimData['priority_level'] ?? 3,
            'status' => 'pending',
        ]);
    }

    private function createClaimItems(Claim $claim, array $items): void
    {
        foreach ($items as $itemData) {
            ClaimItem::create([
                'claim_id' => $claim->id,
                'name' => $itemData['name'],
                'unit_price' => $itemData['unit_price'],
                'quantity' => $itemData['quantity'],
            ]);
        }
        
        // Total amount will be auto-calculated via ClaimItem model events
    }

    private function optimizeBatching(Claim $claim): void
    {
        $insurer = $claim->insurer;
        $batchDate = $claim->getDateForBatching();
        
        // Use the multi-provider batch finding logic that handles capacity automatically
        $batch = $this->findOrCreateAvailableBatch($claim, $insurer, $batchDate);
        $batch->addClaim($claim);
        
        Log::info('Claim added to batch', [
            'claim_id' => $claim->id,
            'batch_id' => $batch->id,
            'batch_claims_count' => $batch->fresh()->total_claims,
            'batch_date' => $batch->batch_date->format('Y-m-d')
        ]);
    }

    private function findOptimalBatch(Claim $claim, Insurer $insurer, Carbon $batchDate): ?Batch
    {
        // Look for existing pending batches for same insurer and date (any provider)
        return Batch::pending()
            ->byInsurer($insurer->code)
            ->byDate($batchDate)
            ->whereRaw('total_claims < ?', [$insurer->max_batch_size])
            ->orderByDesc('total_claims') // Prefer fuller batches for efficiency
            ->first();
    }

    private function findOrCreateAvailableBatch(Claim $claim, Insurer $insurer, Carbon $batchDate): Batch
    {
        return Batch::findOrCreateOptimalBatch($insurer->code, $batchDate);
    }

    private function createNewBatch(Claim $claim, Insurer $insurer, Carbon $batchDate): Batch
    {
        return Batch::createOptimalBatch(
            $insurer->code,
            $batchDate
        );
    }
} 