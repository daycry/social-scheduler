<?php

use App\Http\Controllers\Studio\StudioImageJobController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('brands/{brand}/studio/image-jobs', [StudioImageJobController::class, 'store'])->whereNumber('brand')->name('studio.image-jobs.store');
    Route::get('brands/{brand}/studio/image-jobs/{job}', [StudioImageJobController::class, 'show'])->whereNumber(['brand', 'job'])->name('studio.image-jobs.show');
});
