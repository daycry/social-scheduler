<?php

use App\Connectors\Network;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FirstCommentController;
use App\Http\Controllers\InstagramGridController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\Mcp\OAuth\AuthorizeController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OAuth\OAuthController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SavedTextController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Paginas legales publicas: Google (consentimiento OAuth) y TikTok (auditoria) las exigen.
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms', 'legal.terms')->name('legal.terms');

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
});

// Dashboards de analitica por marca (T-37): Summary y pagina por red.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/{network}', [DashboardController::class, 'network'])
        ->whereIn('network', array_column(Network::cases(), 'value'))->name('dashboard.network');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('brands', BrandController::class);
    Route::get('brands/{brand}/avatar', [BrandController::class, 'avatar'])->whereNumber('brand')->name('brands.avatar');

    // Conexiones de redes sociales por marca (T-12..T-15).
    Route::get('brands/{brand}/connections', [ConnectionController::class, 'index'])->name('brands.connections.index');
    Route::post('brands/{brand}/connections/select', [ConnectionController::class, 'select'])->name('brands.connections.select');
    Route::delete('brands/{brand}/connections/pending', [ConnectionController::class, 'discardPending'])->name('brands.connections.pending.discard');
    Route::delete('brands/{brand}/connections/{account}', [ConnectionController::class, 'destroy'])->name('brands.connections.destroy');
    Route::patch('brands/{brand}/connections/{account}/direct-post', [ConnectionController::class, 'directPost'])->name('brands.connections.direct-post');
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
    // Cuadricula de Instagram (C-02): solo lectura; lo publicado se lee en vivo de Graph con cache de 10 min.
    Route::get('instagram/grid', [InstagramGridController::class, 'index'])->name('instagram.grid');

    // Compositor de publicaciones (T-23).
    Route::get('brands/{brand}/posts/create', [PostController::class, 'create'])->name('brands.posts.create');
    Route::post('brands/{brand}/posts', [PostController::class, 'store'])->middleware('throttle:publish-now')->name('brands.posts.store');
    Route::post('brands/{brand}/posts/validate', [PostController::class, 'validatePost'])->name('brands.posts.validate');
    // «Guardar en biblioteca» con el contenido actual del compositor (sin post guardado, CA-26).
    Route::post('brands/{brand}/library', [LibraryController::class, 'storeFromComposer'])->name('brands.library.store');
    Route::get('posts/{post}/edit', [PostController::class, 'edit'])->name('posts.edit');
    Route::put('posts/{post}', [PostController::class, 'update'])->middleware('throttle:publish-now')->name('posts.update');
    Route::patch('posts/{post}/notes', [PostController::class, 'updateNotes'])->name('posts.notes');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
    // Primer comentario (C-01b): editar el texto de un comentario `fallido` y reintentarlo. Solo web; el MCP no lo expone.
    Route::patch('posts/{post}/targets/{target}/first-comment', [FirstCommentController::class, 'update'])->middleware('throttle:30,1')->name('posts.first-comment.update');
    Route::post('posts/{post}/targets/{target}/first-comment/retry', [FirstCommentController::class, 'retry'])->middleware('throttle:30,1')->name('posts.first-comment.retry');
    Route::post('posts/{post}/targets/{target}/first-comment/published', [FirstCommentController::class, 'markPublished'])->middleware('throttle:30,1')->name('posts.first-comment.published');
    // «Publicar ahora» fuera del compositor (calendario y lista): misma validacion y mismas defensas que el compositor.
    Route::post('posts/{post}/publish-now', [PostController::class, 'publishNow'])->middleware('throttle:publish-now')->name('posts.publish-now');

    // Informes PDF/CSV (T-40). `link` genera un enlace firmado nuevo al pulsar Descargar (no caduca en pantalla);
    // la descarga en si (`reports.download`, mas abajo) va solo con firma, sin sesion.
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::post('reports', [ReportController::class, 'store'])->middleware('throttle:10,1')->name('reports.store');
    Route::get('reports/{report}/link', [ReportController::class, 'link'])->whereNumber('report')->name('reports.link');
    Route::delete('reports/{report}', [ReportController::class, 'destroy'])->whereNumber('report')->name('reports.destroy');

    // Consentimiento OAuth del MCP (ADR-004): `auth` guarda url.intended y vuelve tras el login (2FA incluido).
    Route::get('oauth/authorize', [AuthorizeController::class, 'show'])->middleware('throttle:mcp-oauth-authorize')->name('mcp.oauth.authorize');
    Route::post('oauth/authorize', [AuthorizeController::class, 'decide'])->middleware('throttle:mcp-oauth-authorize')->name('mcp.oauth.authorize.decide');

    // Ajustes · Textos guardados (C-01a). `{id}` a mano: el texto de otro usuario responde 403, no 404.
    Route::get('settings/saved-texts', [SavedTextController::class, 'index'])->name('saved-texts.index');
    Route::post('settings/saved-texts', [SavedTextController::class, 'store'])->name('saved-texts.store');
    Route::put('settings/saved-texts/{id}', [SavedTextController::class, 'update'])->whereNumber('id')->name('saved-texts.update');
    Route::delete('settings/saved-texts/{id}', [SavedTextController::class, 'destroy'])->whereNumber('id')->name('saved-texts.destroy');

    // Campañas (C-03). {id} a mano: la campaña de otro usuario responde 403, no 404.
    Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::get('campaigns/{id}', [CampaignController::class, 'show'])->whereNumber('id')->name('campaigns.show');
    Route::post('campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::put('campaigns/{id}', [CampaignController::class, 'update'])->whereNumber('id')->name('campaigns.update');
    Route::put('campaigns/{id}/archive', [CampaignController::class, 'archive'])->whereNumber('id')->name('campaigns.archive');
    Route::delete('campaigns/{id}', [CampaignController::class, 'destroy'])->whereNumber('id')->name('campaigns.destroy');

    // API keys personales para el MCP (T-28).
    Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::delete('api-keys/{key}', [ApiKeyController::class, 'destroy'])->whereNumber('key')->name('api-keys.destroy');

    // Media (T-17): prefirmado/finalizacion JSON; el binario va directo a R2 (o a `upload` en dev).
    Route::get('media', [MediaController::class, 'index'])->name('media.index');
    Route::post('media', [MediaController::class, 'store'])->name('media.store');
    // Importacion remota (C-04a). `import-url` antes que `{asset}` para que no lo capture el comodin.
    Route::post('media/import-url', [MediaController::class, 'importUrl'])->middleware('throttle:10,1')->name('media.import-url');
    Route::get('media/{asset}', [MediaController::class, 'show'])->whereNumber('asset')->name('media.show');
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

// Descarga de informes SOLO con firma (T-42 / Fase 9): sin sesion, para que la use tambien un agente MCP. La firma
// (5 min) cubre el id del informe y su dueno; el controlador comprueba que coinciden, que el informe esta `ready` y audita.
Route::get('reports/{report}/download/{owner}', [ReportController::class, 'download'])
    ->middleware(['signed', 'throttle:30,1'])->whereNumber(['report', 'owner'])->name('reports.download');

require __DIR__.'/parity.php';
