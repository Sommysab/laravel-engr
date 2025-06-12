<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ClaimController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Healthcare Claims Processing API
Route::prefix('v1')->group(function () {
    
    // Claim submission and management
    Route::get('/claims', [ClaimController::class, 'index'])->name('api.claims.index');
    Route::post('/claims', [ClaimController::class, 'store'])->name('api.claims.store');
    Route::get('/claims/{claim}', [ClaimController::class, 'show'])->name('api.claims.show');
    Route::get('/claims/{claim}/cost-breakdown', [ClaimController::class, 'getCostBreakdown'])->name('api.claims.cost-breakdown');
    
    // Insurer data for frontend dropdowns
    Route::get('/insurers', [ClaimController::class, 'insurers'])->name('api.insurers');
    
    // Batch processing endpoints
    Route::get('/batches', [ClaimController::class, 'getBatches'])->name('api.batches.index');
    Route::get('/batches/{batch}', [ClaimController::class, 'showBatch'])->name('api.batches.show');
    Route::post('/batches/process', [ClaimController::class, 'processBatches'])->name('api.batches.process');
    Route::post('/batches/{batch}/complete', [ClaimController::class, 'completeBatch'])->name('api.batches.complete');
    
    // Analytics and optimization insights
    Route::get('/analytics', [ClaimController::class, 'analytics'])->name('api.analytics');
    Route::get('/cost-analysis', [ClaimController::class, 'costAnalysis'])->name('api.cost-analysis');
});

// Health check endpoint
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'timestamp' => now()->toISOString(),
        'service' => 'Healthcare Claims Processing API'
    ]);
});
