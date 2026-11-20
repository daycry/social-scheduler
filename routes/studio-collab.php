<?php

use App\Http\Controllers\Studio\StudioCollabController;
use App\Http\Controllers\Studio\StudioController;
use App\Http\Controllers\Studio\StudioLinkController;
use App\Http\Middleware\StudioLinkHeaders;
use Illuminate\Support\Facades\Route;

// Loaded by routes/studio.php and inherits the web middleware.
Route::middleware(['auth', 'verified'])->prefix('studio/designs/{design}')->whereNumber('design')->group(function () {
    Route::get('shared/assets/{media}', [StudioController::class, 'sharedAsset'])->whereNumber('media')->name('studio.shared.asset');
    Route::get('shared/fonts/{font}', [StudioController::class, 'sharedFont'])->whereNumber('font')->name('studio.shared.font');
    Route::get('collab', [StudioCollabController::class, 'state'])->name('studio.collab.state');
    Route::get('shares', [StudioCollabController::class, 'shares'])->name('studio.shares.index');
    Route::post('shares', [StudioCollabController::class, 'share'])->middleware('throttle:studio-shares')->name('studio.shares.store');
    Route::patch('shares/{share}', [StudioCollabController::class, 'updateShare'])->whereNumber('share')->name('studio.shares.update');
    Route::delete('shares/{share}', [StudioCollabController::class, 'revokeShare'])->whereNumber('share')->name('studio.shares.destroy');
    Route::get('links', [StudioCollabController::class, 'links'])->name('studio.links.index');
    Route::post('links', [StudioCollabController::class, 'createLink'])->middleware('throttle:studio-links')->name('studio.links.store');
    Route::delete('links/{link}', [StudioCollabController::class, 'revokeLink'])->whereNumber('link')->name('studio.links.destroy');
    Route::get('comments', [StudioCollabController::class, 'comments'])->name('studio.comments.index');
    Route::post('comments', [StudioCollabController::class, 'addComment'])->middleware('throttle:studio.comments.store')->name('studio.comments.store');
    Route::post('comments/{comment}/resolve', [StudioCollabController::class, 'resolve'])->whereNumber('comment')->name('studio.comments.resolve');
    Route::post('comments/{comment}/reopen', [StudioCollabController::class, 'reopen'])->whereNumber('comment')->name('studio.comments.reopen');
    Route::delete('comments/{comment}', [StudioCollabController::class, 'deleteComment'])->whereNumber('comment')->name('studio.comments.destroy');
    Route::get('versions', [StudioCollabController::class, 'versions'])->name('studio.versions.index');
    Route::post('copies', [StudioCollabController::class, 'copy'])->middleware('throttle:studio-copies')->name('studio.copies.store');
    Route::post('versions/{version}/restore', [StudioCollabController::class, 'restoreVersion'])->whereNumber('version')->name('studio.versions.restore');
});

// Anonymous access by link: the token is checked against its hash on every request, so a revoked link stops working at once.
Route::prefix('d/{token}')->middleware(['throttle:studio-link-view', StudioLinkHeaders::class])->where(['token' => '[A-Za-z0-9]{48}', 'media' => '[0-9]+', 'font' => '[0-9]+'])->group(function () {
    Route::get('/', [StudioLinkController::class, 'show'])->name('studio.link.show');
    Route::get('comments', [StudioLinkController::class, 'comments'])->name('studio.link.comments.index');
    Route::post('comments', [StudioLinkController::class, 'addComment'])->middleware('throttle:studio-link-comments')->name('studio.link.comments.store');
    Route::get('assets/{media}', [StudioLinkController::class, 'asset'])->name('studio.link.asset');
    Route::get('fonts/{font}', [StudioLinkController::class, 'font'])->name('studio.link.font');
});
