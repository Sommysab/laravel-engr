<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimItem extends Model
{
    use HasFactory;

    protected $fillable = ['claim_id', 'name', 'unit_price', 'quantity', 'subtotal'];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'integer',
        'subtotal' => 'decimal:2',
    ];

    // Relationships
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    // Auto-calculate subtotal and update parent claim
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($item) {
            // Auto-calculate subtotal
            $item->subtotal = $item->unit_price * $item->quantity;
        });

        static::saved(function ($item) {
            // Update parent claim total amount
            $item->claim->updateTotalAmount();
        });

        static::deleted(function ($item) {
            // Update parent claim total when item is deleted
            if ($item->claim) {
                $item->claim->updateTotalAmount();
            }
        });
    }

    // Helper methods
    public function getFormattedSubtotal(): string
    {
        return '$' . number_format($this->subtotal, 2);
    }

    public function getFormattedUnitPrice(): string
    {
        return '$' . number_format($this->unit_price, 2);
    }
}
