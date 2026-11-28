<?php

use App\Http\Controllers\Studio\StudioAudioController;
use App\Http\Middleware\RequireApiAbility;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->name('api.')->middleware(['auth:sanctum', 'throttle:api-v1', RequireApiAbility::class.':designs:read'])->group(function () {
    Route::get('brands/{brand}/design-audio', [StudioAudioController::class, 'index'])->whereNumber('brand')->name('studio.audio.index');
    Route::get('brands/{brand}/design-audio/{media}', [StudioAudioController::class, 'show'])->whereNumber(['brand', 'media'])->name('studio.audio.show');
});

Route::prefix('api/v1')->name('api.')->middleware(['auth:sanctum', 'throttle:api-v1', RequireApiAbility::class.':designs:write'])->group(function () {
    Route::post('brands/{brand}/design-audio', [StudioAudioController::class, 'store'])->whereNumber('brand')->name('studio.audio.store');
    Route::put('brands/{brand}/design-audio/{media}/upload', [StudioAudioController::class, 'upload'])->whereNumber(['brand', 'media'])->name('studio.audio.upload');
    Route::post('brands/{brand}/design-audio/{media}/complete', [StudioAudioController::class, 'complete'])->whereNumber(['brand', 'media'])->name('studio.audio.complete');
});
