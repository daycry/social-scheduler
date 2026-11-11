<?php

use App\Http\Controllers\Studio\StudioDatasetController;
use Illuminate\Support\Facades\Route;

Route::prefix('brands/{brand}/studio/datasets')->name('studio.datasets.')->middleware(['auth', 'verified'])->group(function () {
    foreach ([['GET', '', 'list'], ['POST', '', 'create'], ['GET', '/{dataset}', 'get'], ['PUT', '/{dataset}', 'replace'], ['DELETE', '/{dataset}', 'archive'], ['POST', '/{dataset}/restore', 'restore'], ['GET', '/{dataset}/revisions', 'history'], ['GET', '/{dataset}/table', 'rows'], ['POST', '/import', 'import'], ['POST', '/{dataset}/import', 'import'], ['GET', '/{dataset}/export', 'export']] as $index => [$method, $path, $action]) {
        Route::match([$method], $path, [StudioDatasetController::class, 'handle'])->whereNumber(['brand', 'dataset'])->defaults('dataset_action', $action)->name($action.'-'.$index);
    }
});
