<?php

use App\Http\Controllers\BrandController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OAuth\OAuthController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('brands', BrandController::class);

    // Conexiones de redes sociales por marca (T-12..T-15).
    Route::get('brands/{brand}/connections', [ConnectionController::class, 'index'])->name('brands.connections.index');
    Route::post('brands/{brand}/connections/select', [ConnectionController::class, 'select'])->name('brands.connections.select');
    Route::delete('brands/{brand}/connections/{account}', [ConnectionController::class, 'destroy'])->name('brands.connections.destroy');
    // POST (con CSRF): iniciar el OAuth cambia el estado de la sesion y puede reactivar cuentas; un GET
    // forzado desde otra web no debe poder lanzarlo.
    Route::post('brands/{brand}/connections/{provider}/connect', [OAuthController::class, 'redirect'])
        ->whereIn('provider', ['meta', 'google', 'tiktok'])->name('brands.connections.connect');
    Route::get('connections/{provider}/callback', [OAuthController::class, 'callback'])
        ->whereIn('provider', ['meta', 'google', 'tiktok'])->name('connections.callback');

    // Avisos in-app (T-25).
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');

    // Calendario (T-24).
    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::patch('posts/{post}/reschedule', [CalendarController::class, 'reschedule'])->name('posts.reschedule');

    // Compositor de publicaciones (T-23).
    Route::get('brands/{brand}/posts/create', [PostController::class, 'create'])->name('brands.posts.create');
    Route::post('brands/{brand}/posts', [PostController::class, 'store'])->name('brands.posts.store');
    Route::post('brands/{brand}/posts/validate', [PostController::class, 'validatePost'])->name('brands.posts.validate');
    Route::get('posts/{post}/edit', [PostController::class, 'edit'])->name('posts.edit');
    Route::put('posts/{post}', [PostController::class, 'update'])->name('posts.update');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');

    // Media (T-17): prefirmado/finalizacion JSON; el binario va directo a R2 (o a `upload` en dev).
    Route::get('media', [MediaController::class, 'index'])->name('media.index');
    Route::post('media', [MediaController::class, 'store'])->name('media.store');
    Route::put('media/{asset}/upload', [MediaController::class, 'upload'])->name('media.upload');
    Route::post('media/{asset}/complete', [MediaController::class, 'complete'])->name('media.complete');
    Route::delete('media/{asset}', [MediaController::class, 'destroy'])->name('media.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
