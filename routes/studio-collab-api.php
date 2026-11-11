<?php

use App\Http\Controllers\Studio\StudioApiController;
use App\Http\Controllers\Studio\StudioCollabController;
use App\Http\Middleware\RequireApiAbility;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/designs/{design}')->name('api.')->whereNumber('design')->middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::middleware(RequireApiAbility::class.':designs:read')->group(function () {
        Route::get('shared/assets/{media}', [StudioApiController::class, 'sharedAsset'])->whereNumber('media')->name('studio.shared.asset');
        Route::get('shared/fonts/{font}', [StudioApiController::class, 'sharedFont'])->whereNumber('font')->name('studio.shared.font');
        Route::get('collab', [StudioCollabController::class, 'state'])->name('studio.collab.state');
        Route::get('shares', [StudioCollabController::class, 'shares'])->name('studio.shares.index');
        Route::get('links', [StudioCollabController::class, 'links'])->name('studio.links.index');
        Route::get('comments', [StudioCollabController::class, 'comments'])->name('studio.comments.index');
        Route::get('versions', [StudioCollabController::class, 'versions'])->name('studio.versions.index');
    });
    Route::middleware(RequireApiAbility::class.':designs:write')->group(function () {
        Route::post('shares', [StudioCollabController::class, 'share'])->middleware('throttle:studio-shares')->name('studio.shares.store');
        Route::patch('shares/{share}', [StudioCollabController::class, 'updateShare'])->whereNumber('share')->name('studio.shares.update');
        Route::delete('shares/{share}', [StudioCollabController::class, 'revokeShare'])->whereNumber('share')->name('studio.shares.destroy');
        Route::post('links', [StudioCollabController::class, 'createLink'])->middleware('throttle:studio-links')->name('studio.links.store');
        Route::delete('links/{link}', [StudioCollabController::class, 'revokeLink'])->whereNumber('link')->name('studio.links.destroy');
        Route::post('comments', [StudioCollabController::class, 'addComment'])->middleware('throttle:60,1')->name('studio.comments.store');
        Route::post('comments/{comment}/resolve', [StudioCollabController::class, 'resolve'])->whereNumber('comment')->name('studio.comments.resolve');
        Route::post('comments/{comment}/reopen', [StudioCollabController::class, 'reopen'])->whereNumber('comment')->name('studio.comments.reopen');
        Route::delete('comments/{comment}', [StudioCollabController::class, 'deleteComment'])->whereNumber('comment')->name('studio.comments.destroy');
        Route::post('copies', [StudioCollabController::class, 'copy'])->middleware('throttle:studio-copies')->name('studio.copies.store');
        Route::post('versions/{version}/restore', [StudioCollabController::class, 'restoreVersion'])->whereNumber('version')->name('studio.versions.restore');
    });
});
