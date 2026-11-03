<?php

use App\Http\Controllers\Studio\StudioController;
use Illuminate\Support\Facades\Route;

// Loaded by routes/web.php and inherits its web middleware.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('studio', [StudioController::class, 'index'])->name('studio.index');
    Route::get('brands/{brand}/studio/designs', [StudioController::class, 'indexBrand'])->whereNumber('brand')->name('studio.designs.index');
    Route::post('brands/{brand}/studio/designs', [StudioController::class, 'store'])->whereNumber('brand')->name('studio.designs.store');
    Route::get('studio/designs/{design}/edit', [StudioController::class, 'edit'])->whereNumber('design')->name('studio.designs.edit');
    Route::get('studio/designs/{design}', [StudioController::class, 'show'])->whereNumber('design')->name('studio.designs.show');
    Route::patch('studio/designs/{design}', [StudioController::class, 'update'])->whereNumber('design')->name('studio.designs.update');
    Route::delete('studio/designs/{design}', [StudioController::class, 'destroy'])->whereNumber('design')->name('studio.designs.destroy');
    Route::post('studio/designs/{design}/duplicate', [StudioController::class, 'duplicate'])->whereNumber('design')->name('studio.designs.duplicate');
    Route::post('studio/designs/{design}/restore', [StudioController::class, 'restore'])->withTrashed()->whereNumber('design')->name('studio.designs.restore');
    Route::get('brands/{brand}/studio/brand-kit', [StudioController::class, 'kit'])->whereNumber('brand')->name('studio.kit.show');
    Route::put('brands/{brand}/studio/brand-kit', [StudioController::class, 'updateKit'])->whereNumber('brand')->name('studio.kit.update');
    Route::get('brands/{brand}/studio/assets', [StudioController::class, 'assets'])->whereNumber('brand')->name('studio.assets.index');
    Route::post('brands/{brand}/studio/assets', [StudioController::class, 'attachAsset'])->whereNumber('brand')->name('studio.assets.store');
    Route::get('brands/{brand}/studio/assets/{media}/file', [StudioController::class, 'assetFile'])->whereNumber(['brand', 'media'])->name('studio.assets.file');
    Route::get('brands/{brand}/studio/folders', [StudioController::class, 'folders'])->whereNumber('brand')->name('studio.folders.index');
    Route::post('brands/{brand}/studio/folders', [StudioController::class, 'storeFolder'])->whereNumber('brand')->name('studio.folders.store');
    Route::delete('studio/folders/{folder}', [StudioController::class, 'destroyFolder'])->whereNumber('folder')->name('studio.folders.destroy');
    Route::get('studio/designs/{design}/exports', [StudioController::class, 'exports'])->whereNumber('design')->name('studio.exports.index');
    Route::post('studio/designs/{design}/exports', [StudioController::class, 'registerExport'])->whereNumber('design')->name('studio.exports.store');
    Route::get('studio/exports/{export}', [StudioController::class, 'showExport'])->whereNumber('export')->name('studio.exports.show');
    Route::post('studio/exports/{export}/post', [StudioController::class, 'createPost'])->whereNumber('export')->name('studio.exports.post');
});
