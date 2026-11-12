<?php

use App\Http\Controllers\Canva\CanvaController;
use App\Http\Middleware\RequireApiAbility;
use App\Mcp\Abilities;
use Illuminate\Support\Facades\Route;

// API REST de la integracion con Canva (T-16). Se carga desde routes/studio-api.php (middleware `api`, sin segundo prefijo).
// La conexion OAuth se hace en la web; aqui solo se lee y se importa/exporta. Nada compra ni publica.
Route::prefix('api/v1')->name('api.')->middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::middleware(RequireApiAbility::class.':'.Abilities::CANVA_READ)->group(function () {
        Route::get('brands/{brand}/canva', [CanvaController::class, 'status'])->whereNumber('brand')->name('canva.status');
        Route::get('brands/{brand}/canva/designs', [CanvaController::class, 'designs'])->middleware('throttle:canva')->whereNumber('brand')->name('canva.designs.index');
        Route::get('brands/{brand}/canva/templates', [CanvaController::class, 'templates'])->middleware('throttle:canva')->whereNumber('brand')->name('canva.templates.index');
        Route::get('brands/{brand}/canva/templates/{template}/dataset', [CanvaController::class, 'templateDataset'])->middleware('throttle:canva')->whereNumber('brand')->name('canva.templates.dataset');
        Route::get('brands/{brand}/canva/jobs/{job}', [CanvaController::class, 'showJob'])->whereNumber(['brand', 'job'])->name('canva.jobs.show');
    });
    Route::middleware([RequireApiAbility::class.':'.Abilities::CANVA_WRITE, 'throttle:canva'])->group(function () {
        Route::post('brands/{brand}/canva/exports', [CanvaController::class, 'storeExport'])->whereNumber('brand')->name('canva.exports.store');
        Route::post('brands/{brand}/canva/autofills', [CanvaController::class, 'storeAutofill'])->whereNumber('brand')->name('canva.autofills.store');
        Route::post('brands/{brand}/canva/uploads', [CanvaController::class, 'storeUpload'])->whereNumber('brand')->name('canva.uploads.store');
    });
});
