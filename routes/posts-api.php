<?php

use App\Http\Controllers\PostApiController;
use App\Http\Middleware\RequireApiAbility;
use Illuminate\Support\Facades\Route;

// API REST de publicaciones (T-17), con el mismo contrato que el compositor web y el MCP. Se carga desde routes/studio-api.php
// (middleware `api`, sin segundo prefijo).
Route::prefix('api/v1')->name('api.')->middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::get('posts/{post}', [PostApiController::class, 'show'])->whereNumber('post')
        ->middleware(RequireApiAbility::class.':posts:read')->name('posts.show');
    Route::post('posts', [PostApiController::class, 'store'])
        ->middleware(RequireApiAbility::class.':posts:write')->name('posts.store');
    Route::match(['put', 'patch'], 'posts/{post}', [PostApiController::class, 'update'])->whereNumber('post')
        ->middleware(RequireApiAbility::class.':posts:write')->name('posts.update');
});
