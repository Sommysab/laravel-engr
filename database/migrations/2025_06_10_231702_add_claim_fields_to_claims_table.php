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
        Schema::table('claims', function (Blueprint $table) {
            // Core claim information
            $table->string('provider_name');
            $table->string('insurer_code');
            $table->foreign('insurer_code')->references('code')->on('insurers');
            
            // Dates for batching logic
            $table->date('encounter_date');
            $table->date('submission_date')->default(now());
            
            // Business logic fields
            $table->string('specialty');
            $table->integer('priority_level')->default(3); // 1-5 scale
            $table->decimal('total_amount', 10, 2)->default(0);
            
            // Processing status
            $table->enum('status', ['pending', 'batched', 'processing', 'completed', 'rejected'])->default('pending');
            $table->foreignId('batch_id')->nullable()->constrained()->onDelete('set null');
            
            // Audit fields
            $table->timestamp('batched_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            
            // Performance indexes for optimal querying
            $table->index(['insurer_code', 'status', 'submission_date'], 'claims_processing_index');
            $table->index(['provider_name', 'encounter_date'], 'claims_batching_index');
            $table->index(['specialty', 'priority_level'], 'claims_specialty_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropForeign(['insurer_code']);
            $table->dropForeign(['batch_id']);
            $table->dropIndex('claims_processing_index');
            $table->dropIndex('claims_batching_index');
            $table->dropIndex('claims_specialty_index');
            
            $table->dropColumn([
                'provider_name', 'insurer_code', 'encounter_date', 'submission_date',
                'specialty', 'priority_level', 'total_amount', 'status', 'batch_id',
                'batched_at', 'processed_at'
            ]);
        });
    }
};
