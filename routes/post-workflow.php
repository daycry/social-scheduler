<?php

use App\Http\Controllers\ApprovalPageController;
use App\Http\Controllers\PostWorkflowController;
use Illuminate\Support\Facades\Route;

// Aprobaciones, publicacion manual, series, lotes y colaboradores de marca (T-19). Se carga desde routes/web.php (middleware web).
// Los ids son enteros y los resuelve el servicio (propietario o aprobador): un post ajeno responde 404 como uno inexistente.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('approvals', [ApprovalPageController::class, 'index'])->name('approvals.index');
    Route::get('series', [ApprovalPageController::class, 'series'])->name('series.index');
    Route::get('posts/{post}/manual', [ApprovalPageController::class, 'manual'])->whereNumber('post')->name('posts.manual.show');

    Route::prefix('workflow')->name('workflow.')->group(function () {
        Route::get('approvals', [PostWorkflowController::class, 'pending'])->name('approvals.pending');
        Route::post('posts/{post}/approval/request', [PostWorkflowController::class, 'requestApproval'])->whereNumber('post')->middleware('throttle:approval.request')->name('approval.request');
        Route::post('posts/{post}/approval/approve', [PostWorkflowController::class, 'approve'])->whereNumber('post')->middleware('throttle:approval.approve')->name('approval.approve');
        Route::post('posts/{post}/approval/reject', [PostWorkflowController::class, 'reject'])->whereNumber('post')->middleware('throttle:approval.reject')->name('approval.reject');
        Route::get('posts/{post}/manual', [PostWorkflowController::class, 'manualContent'])->whereNumber('post')->name('manual.content');
        Route::post('posts/{post}/targets/{target}/manual-published', [PostWorkflowController::class, 'markPublished'])->whereNumber(['post', 'target'])->middleware('throttle:manual.published')->name('manual.published');
        Route::post('posts/{post}/retry', [PostWorkflowController::class, 'retry'])->whereNumber('post')->middleware('throttle:retry')->name('retry');
        Route::post('posts/batch', [PostWorkflowController::class, 'batch'])->middleware('throttle:post-batches')->name('batch');

        Route::get('series', [PostWorkflowController::class, 'seriesIndex'])->name('series.index');
        Route::post('series', [PostWorkflowController::class, 'seriesStore'])->middleware('throttle:series.store')->name('series.store');
        Route::get('series/{series}', [PostWorkflowController::class, 'seriesShow'])->whereNumber('series')->name('series.show');
        Route::patch('series/{series}', [PostWorkflowController::class, 'seriesUpdate'])->whereNumber('series')->middleware('throttle:series.update')->name('series.update');
        Route::post('series/{series}/recover', [PostWorkflowController::class, 'seriesRecover'])->whereNumber('series')->middleware('throttle:series.update')->name('series.recover');
        Route::delete('series/{series}', [PostWorkflowController::class, 'seriesCancel'])->whereNumber('series')->name('series.cancel');

        Route::get('brands/{brand}/collaborators', [PostWorkflowController::class, 'collaborators'])->whereNumber('brand')->name('collaborators.index');
        Route::post('brands/{brand}/collaborators', [PostWorkflowController::class, 'invite'])->whereNumber('brand')->middleware('throttle:brand-collaborators')->name('collaborators.store');
        Route::delete('brands/{brand}/collaborators/{collaborator}', [PostWorkflowController::class, 'revoke'])->whereNumber(['brand', 'collaborator'])->name('collaborators.destroy');
    });
});
