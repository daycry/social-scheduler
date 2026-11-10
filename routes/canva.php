<?php

use App\Http\Controllers\Canva\CanvaController;
use Illuminate\Support\Facades\Route;

/*
| Integracion oficial con Canva (T-16), por marca. Se carga desde routes/web.php. La conexion OAuth se inicia con POST (CSRF);
| `canva/callback` es la Redirect URL y `canva/return` la Return URL que se registran en Canva Developers.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('brands/{brand}/canva', [CanvaController::class, 'index'])->whereNumber('brand')->name('canva.index');
    Route::get('brands/{brand}/canva/status', [CanvaController::class, 'status'])->whereNumber('brand')->name('canva.status');
    Route::post('brands/{brand}/canva/connect', [CanvaController::class, 'connect'])->middleware('throttle:canva-connect')->whereNumber('brand')->name('canva.connect');
    Route::delete('brands/{brand}/canva', [CanvaController::class, 'disconnect'])->whereNumber('brand')->name('canva.disconnect');
    Route::get('canva/callback', [CanvaController::class, 'callback'])->middleware('throttle:canva-connect')->name('canva.callback');
    Route::get('canva/return', [CanvaController::class, 'return'])->middleware('throttle:canva-connect')->name('canva.return');

    Route::middleware('throttle:canva')->group(function () {
        Route::get('brands/{brand}/canva/designs', [CanvaController::class, 'designs'])->whereNumber('brand')->name('canva.designs.index');
        Route::post('brands/{brand}/canva/designs', [CanvaController::class, 'createDesign'])->whereNumber('brand')->name('canva.designs.store');
        Route::get('brands/{brand}/canva/designs/{design}', [CanvaController::class, 'showDesign'])->whereNumber('brand')->name('canva.designs.show');
        Route::post('brands/{brand}/canva/exports', [CanvaController::class, 'storeExport'])->whereNumber('brand')->name('canva.exports.store');
        Route::post('brands/{brand}/canva/uploads', [CanvaController::class, 'storeUpload'])->whereNumber('brand')->name('canva.uploads.store');
        Route::get('brands/{brand}/canva/jobs/{job}', [CanvaController::class, 'showJob'])->whereNumber(['brand', 'job'])->name('canva.jobs.show');
    });
});
