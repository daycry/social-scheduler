<?php

use App\Http\Controllers\BrandController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\OAuth\OAuthController;
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
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
