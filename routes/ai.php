<?php

use App\Mcp\Servers\SchedulerServer;
use Laravel\Mcp\Facades\Mcp;

// Servidor MCP por HTTP. Bearer = API key personal (Sanctum, hash en reposo). Sin sesion web ni CSRF.
// `throttle:mcp-ip` va ANTES de la auth (frena la adivinacion de keys); `throttle:mcp` va despues (por key).
Mcp::web('/mcp', SchedulerServer::class)->middleware(['auth:sanctum', 'throttle:mcp']);
