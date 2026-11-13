<?php

use App\Http\Controllers\Studio\StudioCaptionsController;
use App\Http\Middleware\RequireApiAbility;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->name('api.')->middleware(['auth:sanctum', 'throttle:api-v1'])->group(function () {
    Route::middleware(RequireApiAbility::class.':designs:read')->group(function () {
        Route::get('designs/{design}/captions', [StudioCaptionsController::class, 'show'])->whereNumber('design')->name('studio.captions.show');
        Route::get('designs/{design}/captions/export', [StudioCaptionsController::class, 'export'])->whereNumber('design')->name('studio.captions.export');
    });
    Route::patch('designs/{design}/captions', [StudioCaptionsController::class, 'update'])->whereNumber('design')->middleware(RequireApiAbility::class.':designs:write')->name('studio.captions.update');
});
