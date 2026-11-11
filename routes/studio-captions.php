<?php

use App\Http\Controllers\Studio\StudioCaptionsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('studio/designs/{design}/captions', [StudioCaptionsController::class, 'show'])->whereNumber('design')->name('studio.captions.show');
    Route::patch('studio/designs/{design}/captions', [StudioCaptionsController::class, 'update'])->whereNumber('design')->name('studio.captions.update');
    Route::get('studio/designs/{design}/captions/export', [StudioCaptionsController::class, 'export'])->whereNumber('design')->name('studio.captions.export');
});
