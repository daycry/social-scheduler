<?php

use App\Http\Controllers\AnalyticsApiController;
use App\Http\Controllers\Studio\StudioApiController;
use App\Http\Controllers\Studio\StudioFontController;
use App\Http\Middleware\RequireApiAbility;
use Illuminate\Support\Facades\Route;

require __DIR__.'/studio-captions-api.php';
require __DIR__.'/studio-collab-api.php';
require __DIR__.'/studio-datasets-api.php';

// The application loads this file with the stateless api middleware, without a second prefix.
Route::prefix('api/v1')->name('api.')->middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::middleware(RequireApiAbility::class.':designs:read')->group(function () {
        Route::get('brands/{brand}/design-fonts', [StudioFontController::class, 'index'])->name('studio.fonts.index');
        Route::get('brands/{brand}/design-fonts/{font}', [StudioFontController::class, 'show'])->whereNumber('font')->name('studio.fonts.show');
        Route::get('brands/{brand}/design-fonts/{font}/file', [StudioFontController::class, 'file'])->whereNumber('font')->name('studio.fonts.file');
        Route::get('brands/{brand}/designs', [StudioApiController::class, 'index'])->name('studio.designs.index');
        Route::get('designs/{design}', [StudioApiController::class, 'show'])->name('studio.designs.show');
        Route::get('brands/{brand}/brand-kit', [StudioApiController::class, 'kit'])->name('studio.kit.show');
        Route::get('brands/{brand}/design-folders', [StudioApiController::class, 'folders'])->name('studio.folders.index');
        Route::get('brands/{brand}/design-assets', [StudioApiController::class, 'assets'])->name('studio.assets.index');
        Route::get('brands/{brand}/design-assets/{media}/file', [StudioApiController::class, 'assetFile'])->whereNumber('media')->name('studio.assets.file');
        Route::get('designs/{design}/exports', [StudioApiController::class, 'exports'])->name('studio.exports.index');
        Route::get('design-exports/{export}', [StudioApiController::class, 'showExport'])->name('studio.exports.show');
    });
    Route::middleware(RequireApiAbility::class.':designs:write')->group(function () {
        Route::post('brands/{brand}/design-fonts', [StudioFontController::class, 'store'])->name('studio.fonts.store');
        Route::delete('brands/{brand}/design-fonts/{font}', [StudioFontController::class, 'destroy'])->whereNumber('font')->name('studio.fonts.destroy');
        Route::post('brands/{brand}/design-fonts/{font}/archive', [StudioFontController::class, 'archive'])->whereNumber('font')->name('studio.fonts.archive');
        Route::post('brands/{brand}/design-fonts/{font}/restore', [StudioFontController::class, 'restore'])->whereNumber('font')->name('studio.fonts.restore');
        Route::post('brands/{brand}/designs', [StudioApiController::class, 'store'])->name('studio.designs.store');
        Route::patch('designs/{design}', [StudioApiController::class, 'update'])->name('studio.designs.update');
        Route::delete('designs/{design}', [StudioApiController::class, 'destroy'])->name('studio.designs.destroy');
        Route::post('designs/{design}/duplicate', [StudioApiController::class, 'duplicate'])->name('studio.designs.duplicate');
        Route::post('designs/{design}/restore', [StudioApiController::class, 'restore'])->withTrashed()->name('studio.designs.restore');
        foreach (['template', 'instantiate', 'resize', 'variants', 'apply-kit'] as $operation) {
            Route::post('designs/{design}/'.$operation, [StudioApiController::class, 'automation'])
                ->defaults('operation', $operation)->whereNumber('design')->name('studio.designs.'.$operation);
        }
        Route::put('brands/{brand}/brand-kit', [StudioApiController::class, 'updateKit'])->name('studio.kit.update');
        Route::post('brands/{brand}/design-folders', [StudioApiController::class, 'storeFolder'])->name('studio.folders.store');
        Route::delete('design-folders/{folder}', [StudioApiController::class, 'destroyFolder'])->whereNumber('folder')->name('studio.folders.destroy');
        Route::post('brands/{brand}/design-assets', [StudioApiController::class, 'attachAsset'])->name('studio.assets.store');
        Route::post('designs/{design}/exports', [StudioApiController::class, 'export'])->name('studio.exports.store');
        Route::post('design-exports/{export}/cancel', [StudioApiController::class, 'cancelExport'])->whereNumber('export')->name('studio.exports.cancel');
        Route::post('design-exports/{export}/retry', [StudioApiController::class, 'retryExport'])->whereNumber('export')->name('studio.exports.retry');
        Route::post('design-exports/{export}/post', [StudioApiController::class, 'createPost'])->whereNumber('export')
            ->middleware(RequireApiAbility::class.':posts:write')->name('studio.exports.post');
    });
    Route::get('brands/{brand}/analytics', AnalyticsApiController::class)->middleware(RequireApiAbility::class.':stats:read')->name('analytics');
});

require __DIR__.'/studio-images-api.php';
require __DIR__.'/studio-audio-api.php';
