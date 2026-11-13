<?php

use App\Http\Controllers\Studio\StudioAiController;
use App\Http\Middleware\RequireApiAbility;
use App\Mcp\Abilities;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->name('api.')->middleware(['auth:sanctum', 'throttle:api-v1', RequireApiAbility::class.':'.Abilities::STUDIO_AI])->group(function () {
    Route::get('brands/{brand}/ai', [StudioAiController::class, 'status'])->whereNumber('brand')->name('studio.ai.status');
    Route::post('brands/{brand}/ai-image-jobs', [StudioAiController::class, 'store'])->middleware('throttle:studio-ai')->whereNumber('brand')->name('studio.ai-jobs.store');
    Route::get('brands/{brand}/ai-image-jobs/{job}', [StudioAiController::class, 'show'])->whereNumber(['brand', 'job'])->name('studio.ai-jobs.show');
    Route::post('brands/{brand}/ai-text', [StudioAiController::class, 'text'])->middleware('throttle:studio-ai-text')->whereNumber('brand')->name('studio.ai-text');
});
