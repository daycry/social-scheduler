<?php

use App\Http\Controllers\PostWorkflowController;
use App\Http\Middleware\RequireApiAbility;
use Illuminate\Support\Facades\Route;

// API REST de aprobaciones, publicacion manual, series, lotes y colaboradores (T-19): el mismo controlador que la web y los mismos
// servicios que el MCP. Se carga desde routes/studio-api.php (middleware `api`, sin segundo prefijo).
Route::prefix('api/v1')->name('api.')->middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::middleware(RequireApiAbility::class.':posts:read')->group(function () {
        Route::get('approvals', [PostWorkflowController::class, 'pending'])->name('approvals.pending');
        Route::get('posts/{post}/manual', [PostWorkflowController::class, 'manualContent'])->whereNumber('post')->name('manual.content');
        Route::get('series', [PostWorkflowController::class, 'seriesIndex'])->name('series.index');
        Route::get('series/{series}', [PostWorkflowController::class, 'seriesShow'])->whereNumber('series')->name('series.show');
        Route::get('brands/{brand}/collaborators', [PostWorkflowController::class, 'collaborators'])->whereNumber('brand')->name('collaborators.index');
    });
    Route::middleware(RequireApiAbility::class.':posts:approve')->group(function () {
        Route::post('posts/{post}/approval/approve', [PostWorkflowController::class, 'approve'])->whereNumber('post')->name('approval.approve');
        Route::post('posts/{post}/approval/reject', [PostWorkflowController::class, 'reject'])->whereNumber('post')->name('approval.reject');
        // Invitar o retirar aprobadores decide quien ve y decide los pendientes de la marca: posts:approve, no basta posts:write.
        Route::post('brands/{brand}/collaborators', [PostWorkflowController::class, 'invite'])->whereNumber('brand')->middleware('throttle:brand-collaborators')->name('collaborators.store');
        Route::delete('brands/{brand}/collaborators/{collaborator}', [PostWorkflowController::class, 'revoke'])->whereNumber(['brand', 'collaborator'])->name('collaborators.destroy');
    });
    Route::middleware(RequireApiAbility::class.':posts:write')->group(function () {
        Route::post('posts/{post}/approval/request', [PostWorkflowController::class, 'requestApproval'])->whereNumber('post')->name('approval.request');
        Route::post('posts/{post}/targets/{target}/manual-published', [PostWorkflowController::class, 'markPublished'])->whereNumber(['post', 'target'])->name('manual.published');
        Route::post('posts/{post}/retry', [PostWorkflowController::class, 'retry'])->whereNumber('post')->middleware('throttle:30,1')->name('retry');
        Route::post('posts/batch', [PostWorkflowController::class, 'batch'])->middleware('throttle:post-batches')->name('batch');
        Route::post('series', [PostWorkflowController::class, 'seriesStore'])->middleware('throttle:30,1')->name('series.store');
        Route::patch('series/{series}', [PostWorkflowController::class, 'seriesUpdate'])->whereNumber('series')->name('series.update');
        Route::delete('series/{series}', [PostWorkflowController::class, 'seriesCancel'])->whereNumber('series')->name('series.cancel');
    });
});
