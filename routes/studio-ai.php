<?php

use App\Http\Controllers\Studio\StudioAiController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('brands/{brand}/studio/ai', [StudioAiController::class, 'status'])->whereNumber('brand')->name('studio.ai.status');
    Route::post('brands/{brand}/studio/ai-jobs', [StudioAiController::class, 'store'])->middleware('throttle:studio-ai')->whereNumber('brand')->name('studio.ai-jobs.store');
    Route::get('brands/{brand}/studio/ai-jobs/{job}', [StudioAiController::class, 'show'])->whereNumber(['brand', 'job'])->name('studio.ai-jobs.show');
    Route::post('brands/{brand}/studio/ai-text', [StudioAiController::class, 'text'])->middleware('throttle:studio-ai-text')->whereNumber('brand')->name('studio.ai-text');
});
