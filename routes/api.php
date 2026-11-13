<?php

use App\Http\Controllers\Api\InboxApiController;
use App\Http\Controllers\Api\InsightsApiController;
use App\Http\Controllers\Api\MediaApiController;
use App\Http\Controllers\Api\ReportApiController;
use App\Http\Controllers\Api\SiteApiController;
use App\Http\Controllers\Api\SmartLinkApiController;
use App\Http\Middleware\RequireApiAbility;
use App\Mcp\Abilities;
use Illuminate\Support\Facades\Route;

// API REST de media, buzon, informes, mejores horas, competidores, SmartLinks y sitios (T-29): los mismos servicios que usan la web y el MCP, con las
// mismas abilities que el MCP. Se carga desde routes/studio-api.php (middleware `api`, sin segundo prefijo). La marca siempre va en la ruta.
Route::prefix('api/v1')->name('api.')->middleware(['auth:sanctum', 'throttle:api-v1'])->group(function () {
    // Media del usuario (no es de una marca): el mismo controlador que la web; el binario se sube con `upload` (disco local) o directo a R2.
    Route::middleware(RequireApiAbility::class.':'.Abilities::BRANDS_READ)->group(function () {
        Route::get('media', [MediaApiController::class, 'index'])->name('media.index');
        Route::get('media/{asset}', [MediaApiController::class, 'show'])->whereNumber('asset')->name('media.show');
    });
    Route::middleware(RequireApiAbility::class.':'.Abilities::POSTS_WRITE)->group(function () {
        Route::post('media', [MediaApiController::class, 'store'])->middleware('throttle:api.media.store')->name('media.store');
        Route::post('media/import-url', [MediaApiController::class, 'importUrl'])->middleware('throttle:api.media.import-url')->name('media.import-url');
        Route::put('media/{asset}/upload', [MediaApiController::class, 'upload'])->whereNumber('asset')->name('media.upload');
        Route::post('media/{asset}/complete', [MediaApiController::class, 'complete'])->whereNumber('asset')->name('media.complete');
        Route::delete('media/{asset}', [MediaApiController::class, 'destroy'])->whereNumber('asset')->name('media.destroy');
    });

    Route::get('brands/{brand}/comments', [InboxApiController::class, 'index'])->whereNumber('brand')
        ->middleware(RequireApiAbility::class.':'.Abilities::INBOX_READ)->name('comments.index');
    Route::post('comments/{comment}/reply', [InboxApiController::class, 'reply'])->whereNumber('comment')
        ->middleware([RequireApiAbility::class.':'.Abilities::INBOX_WRITE, 'throttle:api.comments.reply'])->name('comments.reply');

    Route::middleware(RequireApiAbility::class.':'.Abilities::REPORTS_WRITE)->group(function () {
        Route::post('brands/{brand}/reports', [ReportApiController::class, 'store'])->whereNumber('brand')->middleware('throttle:api.reports.store')->name('reports.store');
        Route::get('reports/{report}', [ReportApiController::class, 'show'])->whereNumber('report')->name('reports.show');
    });

    Route::middleware(RequireApiAbility::class.':'.Abilities::STATS_READ)->group(function () {
        Route::get('brands/{brand}/best-times', [InsightsApiController::class, 'bestTimes'])->whereNumber('brand')->name('best-times');
        Route::get('brands/{brand}/competitors', [InsightsApiController::class, 'competitors'])->whereNumber('brand')->middleware('throttle:api.competitors')->name('competitors');
    });

    Route::middleware(RequireApiAbility::class.':'.Abilities::SMARTLINKS_READ)->group(function () {
        Route::get('brands/{brand}/smart-link', [SmartLinkApiController::class, 'show'])->whereNumber('brand')->name('smartlinks.show');
        Route::get('brands/{brand}/smart-link/stats', [SmartLinkApiController::class, 'stats'])->whereNumber('brand')->name('smartlinks.stats');
    });
    Route::put('brands/{brand}/smart-link', [SmartLinkApiController::class, 'save'])->whereNumber('brand')
        ->middleware(RequireApiAbility::class.':'.Abilities::SMARTLINKS_WRITE)->name('smartlinks.save');

    Route::middleware(RequireApiAbility::class.':'.Abilities::SITES_READ)->group(function () {
        Route::get('brands/{brand}/sites', [SiteApiController::class, 'index'])->whereNumber('brand')->name('sites.index');
        Route::get('sites/{site}', [SiteApiController::class, 'show'])->whereNumber('site')->name('sites.show');
    });
    Route::middleware(RequireApiAbility::class.':'.Abilities::SITES_WRITE)->group(function () {
        Route::post('sites/{site}/publish', [SiteApiController::class, 'publish'])->whereNumber('site')->name('sites.publish');
        Route::post('sites/{site}/unpublish', [SiteApiController::class, 'unpublish'])->whereNumber('site')->name('sites.unpublish');
    });
});
