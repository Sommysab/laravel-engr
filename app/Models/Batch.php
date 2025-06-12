<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Batch extends Model
{
    use HasFactory;

    protected $fillable = [
        'insurer_code', 'batch_date', 'total_claims', 'total_amount', 
        'processing_cost', 'status', 'sent_at', 'completed_at',
        'provider_breakdown', 'provider_count'
    ];

    protected $casts = [
        'batch_date' => 'date',
        'total_claims' => 'integer',
        'total_amount' => 'decimal:2',
        'processing_cost' => 'decimal:2',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
        'provider_breakdown' => 'array',
    ];

    // Relationships leveraging existing insurer code structure
    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class, 'insurer_code', 'code');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    // Multi-Provider Business logic methods for cost optimization
    public function addClaim(Claim $claim): void
    {
        $claim->markAsBatched($this);
        $this->updateProviderBreakdown($claim);
        $this->recalculateMetrics();
    }

    public function updateProviderBreakdown(Claim $claim): void
    {
        $breakdown = $this->provider_breakdown ?? [];
        $providerName = $claim->provider_name;

        if (!isset($breakdown[$providerName])) {
            $breakdown[$providerName] = [
                'claims_count' => 0,
                'total_amount' => 0,
                'first_claim_date' => $claim->created_at->toISOString(),
            ];
        }

        $breakdown[$providerName]['claims_count']++;
        $breakdown[$providerName]['total_amount'] += (float) $claim->total_amount;
        $breakdown[$providerName]['last_updated'] = now()->toISOString();

        $this->update([
            'provider_breakdown' => $breakdown,
            'provider_count' => count($breakdown)
        ]);
    }

    public function recalculateMetrics(): void
    {
        $claims = $this->claims()->get();
        
        $this->update([
            'total_claims' => $claims->count(),
            'total_amount' => $claims->sum('total_amount'),
            'processing_cost' => $this->calculateProcessingCost($claims)
        ]);
    }

    private function calculateProcessingCost($claims): float
    {
        if (!$this->insurer) {
            return 0;
        }

        $totalCost = (float) $this->insurer->processing_cost_per_batch;
        
        foreach ($claims as $claim) {
            $totalCost += $this->insurer->getProcessingCostForClaim($claim, $this->batch_date);
        }

        return $totalCost;
    }

    public function canAcceptMoreClaims(): bool
    {
        return $this->status === 'pending' && 
               $this->insurer && 
               $this->total_claims < $this->insurer->max_batch_size;
    }

    public function hasMinimumClaims(): bool
    {
        return $this->insurer && 
               $this->total_claims >= $this->insurer->min_batch_size;
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => 'processing',
            'sent_at' => now(),
        ]);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function getOptimalityScore(): float
    {
        // Higher score = more cost-effective batch
        $fillRatio = $this->insurer ? ($this->total_claims / $this->insurer->max_batch_size) : 0;
        $costEfficiency = $this->total_amount > 0 ? (1 / ($this->processing_cost / $this->total_amount)) : 0;
        
        return ($fillRatio * 0.6) + ($costEfficiency * 0.4);
    }

    // Query scopes
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
        // Find batches that contain claims from this provider
        return $query->whereHas('claims', function ($q) use ($providerName) {
            $q->where('provider_name', $providerName);
        });
    }

    public function scopeMultiProvider($query)
    {
        return $query->where('provider_count', '>', 1);
    }

    public function scopeSingleProvider($query)
    {
        return $query->where('provider_count', '=', 1);
    }

    public function scopeByDate($query, Carbon $date)
    {
        return $query->whereDate('batch_date', $date);
    }

    public function scopeReadyForProcessing($query)
    {
        return $query->pending()->whereHas('insurer', function ($q) {
            $q->where('is_active', true);
        });
    }

    // Static factory methods for multi-provider batch creation
    public static function createOptimalBatch(
        string $insurerCode, 
        Carbon $batchDate
    ): self {
        return self::create([
            'insurer_code' => $insurerCode,
            'batch_date' => $batchDate,
            'status' => 'pending',
            'total_claims' => 0,
            'total_amount' => 0,
            'processing_cost' => 0,
            'provider_breakdown' => [],
            'provider_count' => 0,
        ]);
    }

    public static function findOrCreateOptimalBatch(
        string $insurerCode, 
        Carbon $batchDate
    ): self {
        $currentDate = $batchDate->copy();
        $maxAttempts = 30; // Prevent infinite loop
        $attempts = 0;
        
        do {
            // Find existing batch for this insurer + date combination
            $batch = self::pending()
                ->byInsurer($insurerCode)
                ->byDate($currentDate)
                ->whereRaw('total_claims < (SELECT max_batch_size FROM insurers WHERE code = ?)', [$insurerCode])
                ->first();

            if ($batch) {
                return $batch;
            }

            // Check if a batch already exists for this date (and is full)
            $existingBatch = self::byInsurer($insurerCode)
                ->byDate($currentDate)
                ->first();

            if (!$existingBatch) {
                // No batch exists for this date, create one
                return self::createOptimalBatch($insurerCode, $currentDate);
            }

            // Batch exists and is full, try next business day
            $currentDate = self::getNextBusinessDay($currentDate);
            $attempts++;
            
        } while ($attempts < $maxAttempts);

        // Fallback: create batch for original date if we've hit max attempts
        return self::createOptimalBatch($insurerCode, $batchDate);
    }

    private static function getNextBusinessDay(Carbon $date): Carbon
    {
        $nextDay = $date->copy()->addDay();
        
        // Skip weekends
        while ($nextDay->isWeekend()) {
            $nextDay->addDay();
        }
        
        return $nextDay;
    }

    // Helper methods for API responses
    public function getFormattedProcessingCost(): string
    {
        return '$' . number_format($this->processing_cost, 2);
    }

    public function getFormattedTotalAmount(): string
    {
        return '$' . number_format($this->total_amount, 2);
    }

    public function getDaysUntilProcessing(): int
    {
        return max(0, $this->batch_date->diffInDays(now(), false));
    }

    // Multi-Provider Helper Methods
    public function getProviders(): array
    {
        return array_keys($this->provider_breakdown ?? []);
    }

    public function getProviderStats(string $providerName): ?array
    {
        return $this->provider_breakdown[$providerName] ?? null;
    }

    public function getClaimsByProvider(string $providerName)
    {
        return $this->claims()->where('provider_name', $providerName)->get();
    }

    public function generatePaymentBreakdown(): array
    {
        $payments = [];
        
        foreach ($this->provider_breakdown as $providerName => $stats) {
            $payments[] = [
                'provider_name' => $providerName,
                'claims_count' => $stats['claims_count'],
                'payment_amount' => $stats['total_amount'],
                'processing_fee' => $this->calculateProviderProcessingFee($providerName),
                'net_payment' => $stats['total_amount'] - $this->calculateProviderProcessingFee($providerName),
            ];
        }

        return $payments;
    }

    private function calculateProviderProcessingFee(string $providerName): float
    {
        $providerStats = $this->getProviderStats($providerName);
        if (!$providerStats || !$this->insurer || $this->total_claims == 0) {
            return 0;
        }

        // Proportional fee based on claims in batch
        $providerClaimsRatio = $providerStats['claims_count'] / $this->total_claims;
        return $this->processing_cost * $providerClaimsRatio;
    }

    public function generateProviderNotifications(): array
    {
        $notifications = [];
        
        foreach ($this->getProviders() as $providerName) {
            $stats = $this->getProviderStats($providerName);
            $notifications[$providerName] = [
                'provider_name' => $providerName,
                'batch_id' => $this->id,
                'your_claims' => $stats['claims_count'],
                'your_amount' => $stats['total_amount'],
                'total_batch_claims' => $this->total_claims,
                'batch_status' => $this->status,
                'processing_date' => $this->batch_date->format('Y-m-d'),
            ];
        }

        return $notifications;
    }

    public function toApiResponse(): array
    {
        return [
            'id' => $this->id,
            'insurer_code' => $this->insurer_code,
            'insurer' => $this->insurer,
            'batch_date' => $this->batch_date->format('Y-m-d'),
            'status' => $this->status,
            'total_claims' => $this->total_claims,
            'total_amount' => (float) $this->total_amount,
            'processing_cost' => (float) $this->processing_cost,
            'provider_count' => $this->provider_count,
            'providers' => $this->getProviders(),
            'provider_breakdown' => $this->provider_breakdown,
            'can_process' => $this->hasMinimumClaims(),
            'fill_percentage' => $this->insurer ? 
                round(($this->total_claims / $this->insurer->max_batch_size) * 100, 1) : 0,
            'sent_at' => $this->sent_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
