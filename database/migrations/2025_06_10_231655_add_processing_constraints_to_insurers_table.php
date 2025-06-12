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
        Schema::table('insurers', function (Blueprint $table) {
            // Processing capacity constraints
            $table->integer('daily_capacity')->default(1000);
            $table->integer('min_batch_size')->default(1);
            $table->integer('max_batch_size')->default(100);
            
            // Cost structure
            $table->decimal('processing_cost_per_claim', 8, 2)->default(5.00);
            $table->decimal('processing_cost_per_batch', 8, 2)->default(25.00);
            
            // Business rules
            $table->enum('date_preference', ['encounter_date', 'submission_date'])->default('submission_date');
            $table->json('specialty_multipliers')->nullable();
            
            // Contact information for notifications
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('insurers', function (Blueprint $table) {
            $table->dropColumn([
                'daily_capacity', 'min_batch_size', 'max_batch_size',
                'processing_cost_per_claim', 'processing_cost_per_batch',
                'date_preference', 'specialty_multipliers', 'email', 'is_active'
            ]);
        });
    }
};
