<?php

use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\SiteController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
| Sitios (T-15): constructor, vista previa y publicacion propia en `/s/{slug}`. Se carga desde routes/web.php.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
    Route::post('sites', [SiteController::class, 'store'])->middleware('throttle:20,1')->name('sites.store');
    Route::get('sites/{site}', [SiteController::class, 'edit'])->whereNumber('site')->name('sites.edit');
    Route::put('sites/{site}', [SiteController::class, 'update'])->middleware('throttle:60,1')->whereNumber('site')->name('sites.update');
    Route::delete('sites/{site}', [SiteController::class, 'destroy'])->whereNumber('site')->name('sites.destroy');
    Route::post('sites/{site}/publish', [SiteController::class, 'publish'])->middleware('throttle:30,1')->whereNumber('site')->name('sites.publish');
    Route::post('sites/{site}/unpublish', [SiteController::class, 'unpublish'])->middleware('throttle:30,1')->whereNumber('site')->name('sites.unpublish');
    Route::put('sites/{site}/access', [SiteController::class, 'access'])->middleware('throttle:30,1')->whereNumber('site')->name('sites.access');
    Route::post('sites/{site}/tokens', [SiteController::class, 'storeToken'])->middleware('throttle:30,1')->whereNumber('site')->name('sites.tokens.store');
    Route::delete('sites/{site}/tokens/{token}', [SiteController::class, 'destroyToken'])->middleware('throttle:60,1')->whereNumber(['site', 'token'])->name('sites.tokens.destroy');
    Route::post('sites/{site}/revoke-access', [SiteController::class, 'revokeAll'])->middleware('throttle:30,1')->whereNumber('site')->name('sites.revoke-access');
    Route::delete('sites/{site}/messages', [SiteController::class, 'clearMessages'])->middleware('throttle:30,1')->whereNumber('site')->name('sites.messages.clear');
    Route::delete('sites/{site}/messages/{message}', [SiteController::class, 'destroyMessage'])->middleware('throttle:60,1')->whereNumber(['site', 'message'])->name('sites.messages.destroy');
    Route::get('sites/{site}/preview/{page?}', [SiteController::class, 'preview'])->whereNumber('site')->where('page', '[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?')->name('sites.preview');
    Route::get('sites/{site}/preview-media/{media}', [SiteController::class, 'previewMedia'])->whereNumber(['site', 'media'])->name('sites.preview-media');
});

// Sitios publicados: sin sesion, cookies de Laravel ni CSRF (la unica cookie es la firmada de acceso). Contadores agregados, sin IP.
Route::withoutMiddleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
    PreventRequestForgery::class,
    HandleInertiaRequests::class,
])->middleware('throttle:120,1')->group(function () {
    $slug = '[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])';
    $page = '[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?';
    $key = '[a-f0-9]{8}';
    Route::post('s/{slug}/_access', [PublicSiteController::class, 'unlock'])->middleware('throttle:30,1,site-unlock')->where('slug', $slug)->name('sites.unlock');
    Route::get('s/{slug}/_m/{media}', [PublicSiteController::class, 'media'])->where(['slug' => $slug, 'media' => '[0-9]+'])->name('sites.media');
    Route::post('s/{slug}/_contact/{pageKey}/{blockKey}', [PublicSiteController::class, 'contact'])->middleware('throttle:20,1,site-contact')->where(['slug' => $slug, 'pageKey' => $key, 'blockKey' => $key])->name('sites.contact');
    Route::post('s/{slug}/_vote/{pageKey}/{blockKey}', [PublicSiteController::class, 'vote'])->middleware('throttle:30,1,site-vote')->where(['slug' => $slug, 'pageKey' => $key, 'blockKey' => $key])->name('sites.vote');
    Route::get('s/{slug}', [PublicSiteController::class, 'show'])->where('slug', $slug)->name('sites.public');
    Route::get('s/{slug}/{page}', [PublicSiteController::class, 'show'])->where(['slug' => $slug, 'page' => $page])->name('sites.public.page');
});
