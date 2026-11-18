<?php

use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'edtechlive-api',
        'version' => 'v1',
    ]))->name('api.v1.health');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', MeController::class)->name('api.v1.me');
        Route::get('/organizations', [OrganizationController::class, 'index'])->name('api.v1.organizations.index');
    });
});
