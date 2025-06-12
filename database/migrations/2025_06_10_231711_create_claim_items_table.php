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
        Schema::create('claim_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->decimal('unit_price', 8, 2);
            $table->integer('quantity');
            $table->decimal('subtotal', 10, 2); // Auto-calculated field
            $table->timestamps();
            
            // Performance index for claim relationships
            $table->index(['claim_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claim_items');
    }
};
