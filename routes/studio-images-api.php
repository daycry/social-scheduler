<?php

use App\Http\Controllers\Studio\StudioImageJobController;
use App\Http\Middleware\RequireApiAbility;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->name('api.')->middleware(['auth:sanctum', 'throttle:api-v1'])->group(function () {
    Route::post('brands/{brand}/image-jobs', [StudioImageJobController::class, 'store'])->middleware(RequireApiAbility::class.':designs:write')->whereNumber('brand')->name('studio.image-jobs.store');
    Route::get('brands/{brand}/image-jobs/{job}', [StudioImageJobController::class, 'show'])->middleware(RequireApiAbility::class.':designs:read')->whereNumber(['brand', 'job'])->name('studio.image-jobs.show');
});
