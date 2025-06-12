<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->string('insurer_code');
            $table->foreign('insurer_code')->references('code')->on('insurers');
            $table->date('batch_date');
            
            // Multi-provider support
            $table->json('provider_breakdown')->nullable();
            $table->integer('provider_count')->default(0);
            
            // Batch statistics
            $table->integer('total_claims')->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('processing_cost', 10, 2)->default(0);
            
            // Processing status
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            $table->timestamps();
            
            // Unique constraint for batch identification (Insurer + Date only)
            $table->unique(['insurer_code', 'batch_date'], 'unique_insurer_date_batch');
            $table->index(['insurer_code', 'status'], 'batches_processing_index');
            $table->index(['batch_date', 'status'], 'batches_date_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
