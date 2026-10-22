<?php

use App\Http\Controllers\Mcp\OAuth\MetadataController;
use App\Http\Controllers\Mcp\OAuth\RegisterController;
use App\Http\Controllers\Mcp\OAuth\TokenController;
use App\Mcp\Servers\SchedulerServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// Servidor MCP por HTTP. Bearer = API key personal (Sanctum, hash en reposo). Sin sesion web ni CSRF.
// `throttle:mcp-ip` va ANTES de la auth (frena la adivinacion de keys); `throttle:mcp` va despues (por key).
Mcp::web('/mcp', SchedulerServer::class)->middleware(['auth:sanctum', 'throttle:mcp']);

// OAuth 2.1 del MCP (ADR-004): metadatos de descubrimiento, DCR y token. Fuera de `web` (sin sesion ni CSRF) y sin
// `McpGuard` (solo actua en /mcp). El nombre de la ruta anidada lo usa AddWwwAuthenticateHeader del vendor para el 401.
Route::get('/.well-known/oauth-protected-resource', [MetadataController::class, 'protectedResource'])->name('mcp.oauth.protected-resource');
Route::get('/.well-known/oauth-protected-resource/{path}', [MetadataController::class, 'protectedResource'])
    ->where('path', '.*')->name('mcp.oauth.protected-resource.nested');
Route::get('/.well-known/oauth-authorization-server', [MetadataController::class, 'authorizationServer'])->name('mcp.oauth.authorization-server');

// DCR publico: limitado por IP (10/h) y con redirect_uri restringida por RedirectPolicy.
Route::post('/oauth/register', RegisterController::class)->middleware('throttle:mcp-oauth-register')->name('mcp.oauth.register');

// Canje del codigo por el token (cliente publico + PKCE S256), 30/min por IP.
Route::post('/oauth/token', TokenController::class)->middleware('throttle:mcp-oauth-token')->name('mcp.oauth.token');
