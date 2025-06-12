<?php

namespace App\Actions\Claims;

use App\Models\Batch;
use App\Models\Insurer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class ProcessBatchAction
{
    public function execute(Batch $batch): bool
    {
        try {
            // Validate batch is ready for processing
            if (!$this->canProcessBatch($batch)) {
                Log::warning('Batch not ready for processing', ['batch_id' => $batch->id]);
                return false;
            }

            // Mark as processing
            $batch->markAsProcessing();

            // Send notification to insurer
            $this->sendInsurerNotification($batch);

            Log::info('Batch processed successfully', [
                'batch_id' => $batch->id,
                'insurer' => $batch->insurer_code,
                'claims_count' => $batch->total_claims,
                'total_amount' => $batch->total_amount,
                'processing_cost' => $batch->processing_cost
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to process batch', [
                'batch_id' => $batch->id,
                'error' => $e->getMessage()
            ]);

            $batch->update(['status' => 'failed']);
            return false;
        }
    }

    private function canProcessBatch(Batch $batch): bool
    {
        return $batch->status === 'pending' && 
               $batch->hasMinimumClaims() && 
               $batch->insurer && 
               $batch->insurer->is_active;
    }

    private function sendInsurerNotification(Batch $batch): void
    {
        $insurer = $batch->insurer;
        
        if (!$insurer->email) {
            Log::warning('Insurer has no email address for notification', [
                'batch_id' => $batch->id,
                'insurer_code' => $insurer->code
            ]);
            return;
        }

        // Used Laravel's built-in mail functionality
        Mail::send('emails.batch-notification', [
            'batch' => $batch,
            'insurer' => $insurer,
            'claims' => $batch->claims()->with('items')->get()
        ], function ($message) use ($insurer, $batch) {
            $message->to($insurer->email)
                   ->subject("New Claims Batch Ready for Processing - Batch #{$batch->id}")
                   ->from(config('mail.from.address'), config('mail.from.name'));
        });

        Log::info('Batch notification sent', [
            'batch_id' => $batch->id,
            'insurer_email' => $insurer->email
        ]);
    }

    public function processReadyBatches(): int
    {
        $processedCount = 0;
        
        $readyBatches = Batch::readyForProcessing()
            ->whereHas('claims', null, '>=', 1) // Only batches with claims
            ->get();

        foreach ($readyBatches as $batch) {
            if ($this->execute($batch)) {
                $processedCount++;
            }
        }

        Log::info('Batch processing completed', [
            'processed_count' => $processedCount,
            'total_ready' => $readyBatches->count()
        ]);

        return $processedCount;
    }
} 