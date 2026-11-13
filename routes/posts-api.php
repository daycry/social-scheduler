<?php

use App\Http\Controllers\Api\BrandApiController;
use App\Http\Controllers\Api\PostApiController;
use App\Http\Middleware\RequireApiAbility;
use Illuminate\Support\Facades\Route;

// API REST de marcas y publicaciones (T-17, T-28), con el mismo contrato que el compositor web y el MCP. Se carga desde
// routes/studio-api.php (middleware `api`, sin segundo prefijo). La marca siempre va en la ruta, nunca en la query ni en el cuerpo.
Route::prefix('api/v1')->name('api.')->middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::middleware(RequireApiAbility::class.':brands:read')->group(function () {
        Route::get('brands', [BrandApiController::class, 'index'])->name('brands.index');
        Route::get('brands/{brand}', [BrandApiController::class, 'show'])->whereNumber('brand')->name('brands.show');
        Route::get('brands/{brand}/accounts', [BrandApiController::class, 'accounts'])->whereNumber('brand')->name('accounts.index');
    });
    Route::middleware(RequireApiAbility::class.':posts:read')->group(function () {
        Route::get('brands/{brand}/posts', [PostApiController::class, 'index'])->whereNumber('brand')->name('posts.index');
        Route::get('posts/{post}', [PostApiController::class, 'show'])->whereNumber('post')->name('posts.show');
    });
    Route::middleware(RequireApiAbility::class.':posts:write')->group(function () {
        Route::post('brands/{brand}/posts', [PostApiController::class, 'store'])->whereNumber('brand')->name('posts.store');
        Route::match(['put', 'patch'], 'posts/{post}', [PostApiController::class, 'update'])->whereNumber('post')->name('posts.update');
        Route::delete('posts/{post}', [PostApiController::class, 'destroy'])->whereNumber('post')->middleware('throttle:30,1')->name('posts.destroy');
        Route::post('posts/{post}/cancel', [PostApiController::class, 'cancel'])->whereNumber('post')->middleware('throttle:30,1')->name('posts.cancel');
        Route::post('posts/{post}/publish', [PostApiController::class, 'publish'])->whereNumber('post')->middleware('throttle:30,1')->name('posts.publish');
    });
});
