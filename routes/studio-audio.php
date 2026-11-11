<?php

use App\Http\Controllers\Studio\StudioAudioController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('brands/{brand}/studio/audio', [StudioAudioController::class, 'index'])->whereNumber('brand')->name('studio.audio.index');
    Route::get('brands/{brand}/studio/audio/{media}', [StudioAudioController::class, 'show'])->whereNumber(['brand', 'media'])->name('studio.audio.show');
    Route::post('brands/{brand}/studio/audio', [StudioAudioController::class, 'store'])->whereNumber('brand')->name('studio.audio.store');
    Route::put('brands/{brand}/studio/audio/{media}/upload', [StudioAudioController::class, 'upload'])->whereNumber(['brand', 'media'])->name('studio.audio.upload');
    Route::post('brands/{brand}/studio/audio/{media}/complete', [StudioAudioController::class, 'complete'])->whereNumber(['brand', 'media'])->name('studio.audio.complete');
});
