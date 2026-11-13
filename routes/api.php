<?php

use App\Http\Controllers\Api\InboxApiController;
use App\Http\Controllers\Api\InsightsApiController;
use App\Http\Controllers\Api\ReportApiController;
use App\Http\Controllers\Api\SiteApiController;
use App\Http\Controllers\Api\SmartLinkApiController;
use App\Http\Controllers\MediaController;
use App\Http\Middleware\RequireApiAbility;
use App\Mcp\Abilities;
use Illuminate\Support\Facades\Route;

// API REST de media, buzon, informes, mejores horas, competidores, SmartLinks y sitios (T-29): los mismos servicios que usan la web y el MCP, con las
// mismas abilities que el MCP. Se carga desde routes/studio-api.php (middleware `api`, sin segundo prefijo). La marca siempre va en la ruta.
Route::prefix('api/v1')->name('api.')->middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    // Media del usuario (no es de una marca): el mismo controlador que la web; el binario se sube con `upload` (disco local) o directo a R2.
    Route::middleware(RequireApiAbility::class.':'.Abilities::BRANDS_READ)->group(function () {
        Route::get('media', [MediaController::class, 'index'])->name('media.index');
        Route::get('media/{asset}', [MediaController::class, 'show'])->whereNumber('asset')->name('media.show');
    });
    Route::middleware(RequireApiAbility::class.':'.Abilities::POSTS_WRITE)->group(function () {
        Route::post('media', [MediaController::class, 'store'])->middleware('throttle:60,1')->name('media.store');
        Route::post('media/import-url', [MediaController::class, 'importUrl'])->middleware('throttle:10,1')->name('media.import-url');
        Route::put('media/{asset}/upload', [MediaController::class, 'upload'])->whereNumber('asset')->name('media.upload');
        Route::post('media/{asset}/complete', [MediaController::class, 'complete'])->whereNumber('asset')->name('media.complete');
        Route::delete('media/{asset}', [MediaController::class, 'destroy'])->whereNumber('asset')->name('media.destroy');
    });

    Route::get('brands/{brand}/comments', [InboxApiController::class, 'index'])->whereNumber('brand')
        ->middleware(RequireApiAbility::class.':'.Abilities::INBOX_READ)->name('comments.index');
    Route::post('comments/{comment}/reply', [InboxApiController::class, 'reply'])->whereNumber('comment')
        ->middleware([RequireApiAbility::class.':'.Abilities::INBOX_WRITE, 'throttle:30,1'])->name('comments.reply');

    Route::middleware(RequireApiAbility::class.':'.Abilities::REPORTS_WRITE)->group(function () {
        Route::post('brands/{brand}/reports', [ReportApiController::class, 'store'])->whereNumber('brand')->middleware('throttle:20,1')->name('reports.store');
        Route::get('reports/{report}', [ReportApiController::class, 'show'])->whereNumber('report')->name('reports.show');
    });

    Route::middleware(RequireApiAbility::class.':'.Abilities::STATS_READ)->group(function () {
        Route::get('brands/{brand}/best-times', [InsightsApiController::class, 'bestTimes'])->whereNumber('brand')->name('best-times');
        Route::get('brands/{brand}/competitors', [InsightsApiController::class, 'competitors'])->whereNumber('brand')->middleware('throttle:30,1')->name('competitors');
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
