<?php

use App\Http\Controllers\AdsController;
use App\Http\Controllers\AutolistController;
use App\Http\Controllers\BestTimesController;
use App\Http\Controllers\CompetitorController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\PublicSmartLinkController;
use App\Http\Controllers\SmartLinkController;
use App\Http\Controllers\TrashController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
| Rutas de paridad con Metricool (iniciativa metricool-parity, fase P2). Se cargan desde routes/web.php con una sola
| linea `require`, asi que heredan el grupo `web`.
*/

// Paginas de gestion (autenticadas).
Route::middleware(['auth', 'verified'])->group(function () {
    // Planificacion · Mejores horas (PA-05).
    Route::get('best-times', [BestTimesController::class, 'index'])->name('best-times.index');

    // Planificacion · Biblioteca (PA-06).
    Route::get('library', [LibraryController::class, 'index'])->name('library.index');
    Route::post('library', [LibraryController::class, 'store'])->name('library.store');
    Route::post('library/{template}/use', [LibraryController::class, 'use'])->whereNumber('template')->name('library.use');
    Route::delete('library/{template}', [LibraryController::class, 'destroy'])->whereNumber('template')->name('library.destroy');

    // Planificacion · Papelera (PA-06). `withTrashed`: el binding encuentra tambien las borradas (siempre dentro del UserScope).
    Route::get('trash', [TrashController::class, 'index'])->name('trash.index');
    Route::post('trash/{post}/restore', [TrashController::class, 'restore'])->withTrashed()->whereNumber('post')->name('trash.restore');
    Route::delete('trash/{post}', [TrashController::class, 'destroy'])->withTrashed()->whereNumber('post')->name('trash.destroy');

    // SmartLinks (PA-07): el microsite de la marca.
    Route::get('smartlinks', [SmartLinkController::class, 'index'])->name('smartlinks.index');
    Route::post('smartlinks', [SmartLinkController::class, 'save'])->name('smartlinks.save');
    Route::delete('smartlinks/{smartLink}', [SmartLinkController::class, 'destroy'])->whereNumber('smartLink')->name('smartlinks.destroy');

    // Analytics · Competidores de Instagram (PA-08).
    Route::get('competitors', [CompetitorController::class, 'index'])->name('competitors.index');
    Route::post('competitors', [CompetitorController::class, 'store'])->middleware('throttle:20,1')->name('competitors.store');
    Route::delete('competitors/{competitor}', [CompetitorController::class, 'destroy'])->whereNumber('competitor')->name('competitors.destroy');

    // Planificacion · Autolistas (PA-09).
    Route::get('autolists', [AutolistController::class, 'index'])->name('autolists.index');
    Route::post('autolists', [AutolistController::class, 'store'])->name('autolists.store');
    Route::patch('autolists/{autolist}', [AutolistController::class, 'update'])->whereNumber('autolist')->name('autolists.update');
    Route::delete('autolists/{autolist}', [AutolistController::class, 'destroy'])->whereNumber('autolist')->name('autolists.destroy');
    Route::post('autolists/{autolist}/items', [AutolistController::class, 'addItem'])->whereNumber('autolist')->name('autolists.items.store');
    Route::delete('autolists/{autolist}/items/{item}', [AutolistController::class, 'removeItem'])->whereNumber(['autolist', 'item'])->name('autolists.items.destroy');
    Route::post('autolists/{autolist}/generate', [AutolistController::class, 'generate'])->middleware('throttle:20,1')->whereNumber('autolist')->name('autolists.generate');

    // Inbox de comentarios de Instagram/Facebook (PA-10).
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::post('inbox/sync', [InboxController::class, 'sync'])->middleware('throttle:6,1')->name('inbox.sync');
    Route::post('inbox/read-all', [InboxController::class, 'readAll'])->name('inbox.read-all');
    Route::post('inbox/{comment}/read', [InboxController::class, 'read'])->whereNumber('comment')->name('inbox.read');
    Route::post('inbox/{comment}/reply', [InboxController::class, 'reply'])->middleware('throttle:30,1')->whereNumber('comment')->name('inbox.reply');
    Route::post('inbox/{comment}/hide', [InboxController::class, 'hide'])->middleware('throttle:30,1')->whereNumber('comment')->name('inbox.hide');

    // Analytics · Meta Ads, solo lectura (PA-11).
    Route::get('ads', [AdsController::class, 'index'])->middleware('throttle:30,1')->name('ads.index');
});

// SmartLinks publicos (PA-07): sin login, sin sesion ni cookies (ni Set-Cookie ni CSRF) y sin el middleware de Inertia
// (que lee la sesion). Lo unico que se guarda son contadores diarios agregados.
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
    Route::get('l/{slug}', [PublicSmartLinkController::class, 'show'])->where('slug', $slug)->name('smartlinks.public');
    Route::get('l/{slug}/go/{block}', [PublicSmartLinkController::class, 'go'])->where(['slug' => $slug, 'block' => '[0-9]+'])->name('smartlinks.go');
});
