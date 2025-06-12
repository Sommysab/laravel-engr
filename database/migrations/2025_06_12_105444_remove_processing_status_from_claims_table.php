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
            // Update the status enum to remove 'processing' since claims never use this status
            // Claims workflow: pending -> batched -> completed (or rejected)
            $table->enum('status', ['pending', 'batched', 'completed', 'rejected'])
                  ->default('pending')
                  ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            // Restore the original enum with 'processing' status
            $table->enum('status', ['pending', 'batched', 'processing', 'completed', 'rejected'])
                  ->default('pending')
                  ->change();
        });
    }
};
