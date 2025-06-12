<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class Claim extends Model
{
    use HasFactory;

    protected $table = 'claims';
    
    protected $fillable = [
        'provider_name', 'insurer_code', 'encounter_date', 'submission_date',
        'specialty', 'priority_level', 'total_amount', 'status', 'batch_id'
    ];

    protected $casts = [
        'encounter_date' => 'date',
        'submission_date' => 'date',
        'total_amount' => 'decimal:2',
        'priority_level' => 'integer',
        'batched_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    // Relationships utilizing existing insurer code
    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class, 'insurer_code', 'code');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClaimItem::class);
    }

    // Business logic methods
    public function calculateTotalAmount(): float
    {
        return (float) $this->items()->sum('subtotal');
    }

    public function updateTotalAmount(): void
    {
        $this->update(['total_amount' => $this->calculateTotalAmount()]);
    }

    public function getDateForBatching(): Carbon
    {
        $dateField = $this->insurer->getDateFieldForBatching();
        return $this->{$dateField};
    }

    public function canBeBatched(): bool
    {
        return $this->status === 'pending' && $this->batch_id === null;
    }

    public function markAsBatched(Batch $batch): void
    {
        $this->update([
            'status' => 'batched',
            'batch_id' => $batch->id,
            'batched_at' => now(),
        ]);
    }

    public function getOptimalityScore(): float
    {
        // Higher score = better for batching (considering priority and amount)
        $priorityWeight = $this->priority_level * 0.3;
        
        // Amount weight normaliser.
        //  - 10 000 is the business-defined pivot where “large” claims reach max weight.
        //  - Keep in sync with the tiers in Insurer::getValueBasedMultiplier().
        //  - Replace with a data-driven value (e.g. rolling 3-month median * 1.5),
        //    or move to config('claims.amount_normaliser') when we need it to adapt.
        $amountWeight = min(($this->total_amount / 10000), 1) * 0.7; // Normalize large amounts
        
        return $priorityWeight + $amountWeight;
    }

    // Query scopes for batching optimization
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeByInsurer($query, string $insurerCode)
    {
        return $query->where('insurer_code', $insurerCode);
    }

    public function scopeByProvider($query, string $providerName)
    {
        return $query->where('provider_name', $providerName);
    }

    public function scopeByDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('submission_date', [$startDate, $endDate]);
    }

    public function scopeBySpecialty($query, string $specialty)
    {
        return $query->where('specialty', $specialty);
    }

    public function scopeOptimalForBatching($query, Insurer $insurer)
    {
        return $query->pending()
                    ->byInsurer($insurer->code)
                    ->orderByDesc('priority_level')
                    ->orderByDesc('total_amount')
                    ->orderBy('submission_date');
    }

    public function scopeReadyForBatching($query, string $providerName, Carbon $batchDate, Insurer $insurer)
    {
        $dateField = $insurer->getDateFieldForBatching();
        
        return $query->pending()
                    ->byProvider($providerName)
                    ->byInsurer($insurer->code)
                    ->whereDate($dateField, $batchDate);
    }

    // Model events
    protected static function boot()
    {
        parent::boot();

        static::created(function ($claim) {
            // Auto-trigger batching optimization when new claim is created
            // Only for direct model creation (imports, etc.) - not when using CreateClaimAction
            if ($claim->status === 'pending' && !$claim->batch_id && $claim->canBeBatched()) {
                // Check if we're in an API request context - if so, skip auto-batching 
                // since CreateClaimAction should handle it
                $isApiRequest = request() && request()->is('api/*');
                
                if (!$isApiRequest) {
                    static::triggerAutomaticBatching($claim);
                }
                
                Log::info('Claim created - auto-batching evaluated', [
                    'claim_id' => $claim->id,
                    'provider' => $claim->provider_name,
                    'insurer' => $claim->insurer_code,
                    'batch_id' => $claim->batch_id,
                    'auto_batched' => $claim->batch_id ? true : false,
                    'is_api_request' => $isApiRequest
                ]);
            }
        });

        static::updated(function ($claim) {
            // Recalculate batch metrics when claim amount changes
            if ($claim->isDirty('total_amount') && $claim->batch) {
                $claim->batch->recalculateMetrics();
                
                Log::info('Claim updated - batch metrics recalculated', [
                    'claim_id' => $claim->id,
                    'batch_id' => $claim->batch_id,
                    'new_amount' => $claim->total_amount
                ]);
            }
        });

        static::deleting(function ($claim) {
            // Remove from batch before deletion and recalculate metrics
            if ($claim->batch) {
                $batchId = $claim->batch_id;
                $claim->update(['batch_id' => null, 'status' => 'cancelled']);
                
                $batch = Batch::find($batchId);
                if ($batch) {
                    $batch->recalculateMetrics();
                    
                    // If batch becomes empty, mark it for deletion
                    if ($batch->total_claims === 0) {
                        $batch->delete();
                    }
                }
                
                Log::info('Claim deleted - removed from batch', [
                    'claim_id' => $claim->id,
                    'batch_id' => $batchId
                ]);
            }
        });
    }

    /**
     * Trigger automatic batching for claims created outside of CreateClaimAction
     * This handles direct model creation, imports, or other scenarios
     */
    private static function triggerAutomaticBatching(Claim $claim): void
    {
        try {
            $insurer = $claim->insurer;
            if (!$insurer || !$insurer->is_active) {
                Log::warning('Cannot auto-batch claim - invalid or inactive insurer', [
                    'claim_id' => $claim->id,
                    'insurer_code' => $claim->insurer_code
                ]);
                return;
            }

            $batchDate = $claim->getDateForBatching();
            
            // Find optimal existing batch
            $batch = static::findOptimalBatchForClaim($claim, $insurer, $batchDate);
            
            if ($batch && $batch->canAcceptMoreClaims()) {
                $batch->addClaim($claim);
                
                Log::info('Claim auto-batched to existing batch', [
                    'claim_id' => $claim->id,
                    'batch_id' => $batch->id,
                    'batch_claims_count' => $batch->total_claims
                ]);
            } else {
                // Use the same smart batching logic as CreateClaimAction
                $newBatch = static::findOrCreateAvailableBatchForClaim($claim, $insurer, $batchDate);
                $newBatch->addClaim($claim);
                
                Log::info('Claim auto-batched to new batch', [
                    'claim_id' => $claim->id,
                    'batch_id' => $newBatch->id
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Auto-batching failed for claim', [
                'claim_id' => $claim->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Find or create an available batch for a claim, handling full batches by using next available date
     */
    private static function findOrCreateAvailableBatchForClaim(Claim $claim, Insurer $insurer, Carbon $batchDate): Batch
    {
        return Batch::findOrCreateOptimalBatch($insurer->code, $batchDate);
    }

    /**
     * Find the most optimal batch for a claim based on existing batching logic
     */
    private static function findOptimalBatchForClaim(Claim $claim, Insurer $insurer, Carbon $batchDate): ?Batch
    {
        return Batch::pending()
            ->byInsurer($insurer->code)
            ->byDate($batchDate)
            ->whereRaw('total_claims < ?', [$insurer->max_batch_size])
            ->orderByDesc('total_claims') // Prefer fuller batches for efficiency
            ->first();
    }
} 