<?php

use App\Http\Controllers\Studio\StudioDatasetController;
use App\Http\Middleware\RequireApiAbility;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/brands/{brand}/datasets')->name('api.studio.datasets.')->middleware(['auth:sanctum', 'throttle:api-v1'])->group(function () {
    foreach ([['GET', '', 'list', 'read'], ['POST', '', 'create', 'write'], ['GET', '/{dataset}', 'get', 'read'], ['PUT', '/{dataset}', 'replace', 'write'], ['DELETE', '/{dataset}', 'archive', 'write'], ['POST', '/{dataset}/restore', 'restore', 'write'], ['GET', '/{dataset}/revisions', 'history', 'read']] as [$method, $path, $action, $ability]) {
        Route::match([$method], $path, [StudioDatasetController::class, 'handle'])->whereNumber(['brand', 'dataset'])->defaults('dataset_action', $action)->middleware(RequireApiAbility::class.':designs:'.$ability)->name($action);
    }
    Route::get('/{dataset}/table', [StudioDatasetController::class, 'handle'])->whereNumber(['brand', 'dataset'])->defaults('dataset_action', 'rows')->middleware(RequireApiAbility::class.':designs:read')->name('rows');
    Route::get('/{dataset}/export', [StudioDatasetController::class, 'handle'])->whereNumber(['brand', 'dataset'])->defaults('dataset_action', 'export')->middleware(RequireApiAbility::class.':designs:read')->name('export');
    Route::post('/import', [StudioDatasetController::class, 'handle'])->whereNumber('brand')->defaults('dataset_action', 'import')->middleware(RequireApiAbility::class.':designs:write')->name('import-create');
    Route::post('/{dataset}/import', [StudioDatasetController::class, 'handle'])->whereNumber(['brand', 'dataset'])->defaults('dataset_action', 'import')->middleware(RequireApiAbility::class.':designs:write')->name('import-replace');
});
