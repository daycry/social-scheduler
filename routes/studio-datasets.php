<?php

use App\Http\Controllers\Studio\StudioDatasetController;
use Illuminate\Support\Facades\Route;

Route::prefix('brands/{brand}/studio/datasets')->name('studio.datasets.')->middleware(['auth', 'verified'])->group(function () {
    foreach ([['GET', '', 'list', 'list'], ['POST', '', 'create', 'create'], ['GET', '/{dataset}', 'get', 'get'], ['PUT', '/{dataset}', 'replace', 'replace'], ['DELETE', '/{dataset}', 'archive', 'archive'], ['POST', '/{dataset}/restore', 'restore', 'restore'], ['GET', '/{dataset}/revisions', 'history', 'history'], ['GET', '/{dataset}/table', 'rows', 'rows'], ['POST', '/import', 'import', 'import-create'], ['POST', '/{dataset}/import', 'import', 'import-replace'], ['GET', '/{dataset}/export', 'export', 'export']] as [$method, $path, $action, $name]) {
        Route::match([$method], $path, [StudioDatasetController::class, 'handle'])->whereNumber(['brand', 'dataset'])->defaults('dataset_action', $action)->name($name);
    }
});
