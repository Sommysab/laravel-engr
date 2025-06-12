<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Insurer extends Model
{
    use HasFactory;

    protected $table = 'insurers';
    
    protected $fillable = [
        'name', 'code', 'daily_capacity', 'min_batch_size', 'max_batch_size',
        'processing_cost_per_claim', 'processing_cost_per_batch', 'date_preference',
        'specialty_multipliers', 'email', 'is_active'
    ];

    protected $casts = [
        'specialty_multipliers' => 'array',
        'is_active' => 'boolean',
        'daily_capacity' => 'integer',
        'min_batch_size' => 'integer',
        'max_batch_size' => 'integer',
        'processing_cost_per_claim' => 'decimal:2',
        'processing_cost_per_batch' => 'decimal:2',
    ];

    // Relationships leveraging existing structure
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class, 'insurer_code', 'code');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class, 'insurer_code', 'code');
    }

    // Business logic methods for cost optimization
    public function getProcessingCostForClaim(Claim $claim, Carbon $processDate = null): float
    {
        $baseCost = (float) $this->processing_cost_per_claim;
        
        // 1. Apply specialty multiplier if available
        if ($this->specialty_multipliers && isset($this->specialty_multipliers[$claim->specialty])) {
            $baseCost *= $this->specialty_multipliers[$claim->specialty];
        }
        
        // 2. Priority multiplier (1-5 scale) - higher priority = higher cost
        $priorityMultiplier = 1 + (($claim->priority_level - 1) * 0.1); // bump by 10% for each priority level
        
        // 3. Time of month multiplier - processing costs increase linearly from 20% to 50%
        $timeMultiplier = $this->getTimeOfMonthMultiplier($processDate);
        
        // 4. Monetary value multiplier - higher value claims cost more to process
        $valueMultiplier = $this->getValueBasedMultiplier($claim->total_amount);
        
        return $baseCost * $priorityMultiplier * $timeMultiplier * $valueMultiplier;
    }

    /**
     * Calculate time-of-month cost multiplier
     * Processing costs increase linearly from 20% (1.2x) on the 1st to 50% (1.5x) on the 30th
     */
    public function getTimeOfMonthMultiplier(Carbon $date = null): float 
    {
        $date = $date ?: now();
        $dayOfMonth = $date->day;
        $daysInMonth = $date->daysInMonth;
        
        // Linear progression from 1.2x (20% increase) to 1.5x (50% increase)
        $baseMultiplier = 1.2;    // 20% increase on day 1
        $maxMultiplier = 1.5;     // 50% increase on last day
        
        // Calculate progression (0.0 to 1.0)
        $progression = ($dayOfMonth - 1) / max(1, $daysInMonth - 1);
        
        return $baseMultiplier + ($progression * ($maxMultiplier - $baseMultiplier));
    }

    /**
     * Calculate monetary value-based cost multiplier
     * Higher value claims require more careful processing and verification
     */
    public function getValueBasedMultiplier(float $claimAmount): float 
    {
        // Tiered multiplier system based on claim value
        // - Thresholds reflect business-defined "high value" breakpoints, not the statistical average.
        // - Keep these in sync with batching normalization logic (see Claim::getOptimalityScore()).
        // - For future flexibility, we can consider replacing hardcoded values with a data-driven approach
        //   (e.g. rolling median, percentiles, or a configurable value).
        if ($claimAmount >= 25000) return 2.0;      // Very high value: +100% (extensive review)
        if ($claimAmount >= 15000) return 1.7;      // High value: +70% (detailed review)
        if ($claimAmount >= 10000) return 1.5;      // Medium-high: +50% (enhanced review)
        if ($claimAmount >= 5000) return 1.3;       // Medium: +30% (standard review)
        if ($claimAmount >= 1000) return 1.1;       // Low-medium: +10% (basic review)
        return 1.0;                                 // Standard: no additional cost
    }

    /**
     * Get detailed cost breakdown for transparency
     */
    public function getCostBreakdown(Claim $claim, Carbon $processDate = null): array
    {
        $baseCost = (float) $this->processing_cost_per_claim;
        $processDate = $processDate ?: now();
        
        // Calculate all multipliers
        $specialtyMultiplier = 1.0;
        if ($this->specialty_multipliers && isset($this->specialty_multipliers[$claim->specialty])) {
            $specialtyMultiplier = $this->specialty_multipliers[$claim->specialty];
        }
        
        $priorityMultiplier = 1 + (($claim->priority_level - 1) * 0.1);
        $timeMultiplier = $this->getTimeOfMonthMultiplier($processDate);
        $valueMultiplier = $this->getValueBasedMultiplier($claim->total_amount);
        
        $finalCost = $baseCost * $specialtyMultiplier * $priorityMultiplier * $timeMultiplier * $valueMultiplier;
        
        return [
            'base_cost' => round($baseCost, 2),
            'specialty_multiplier' => round($specialtyMultiplier, 2),
            'priority_multiplier' => round($priorityMultiplier, 2),
            'time_multiplier' => round($timeMultiplier, 2),
            'value_multiplier' => round($valueMultiplier, 2),
            'final_cost' => round($finalCost, 2),
            'factors' => [
                'specialty' => $claim->specialty,
                'priority_level' => $claim->priority_level,
                'claim_amount' => $claim->total_amount,
                'process_date' => $processDate->format('Y-m-d'),
                'day_of_month' => $processDate->day
            ]
        ];
    }

    public function canAcceptBatch(int $claimCount): bool
    {
        return $claimCount >= $this->min_batch_size && 
               $claimCount <= $this->max_batch_size &&
               $this->is_active;
    }

    public function getDateFieldForBatching(): string
    {
        return $this->date_preference ?? 'submission_date';
    }

    public function calculateBatchProcessingCost(int $claimCount, float $totalClaimAmount): float
    {
        return (float) $this->processing_cost_per_batch + ($claimCount * (float) $this->processing_cost_per_claim);
    }

    // Query scopes for optimization
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    // Helper for API responses
    public function toArray()
    {
        $array = parent::toArray();
        $array['specialty_multipliers'] = $this->specialty_multipliers ?? [];
        return $array;
    }
} 