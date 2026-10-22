<?php

namespace App\Console\Commands;

use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use Illuminate\Console\Command;

/** Purga del servidor OAuth del MCP (CA-31): clientes sin token vivo en N dias y codigos de autorizacion caducados. */
class McpOAuthPrune extends Command
{
    protected $signature = 'mcp:oauth-prune {--days=30 : Dias sin token vivo antes de borrar un cliente}';

    protected $description = 'Borra los clientes OAuth del MCP sin token vivo en 30 dias y los codigos de autorizacion caducados';

    public function handle(): int
    {
        $limit = now()->subDays(max(1, (int) $this->option('days')));

        $codes = OAuthAuthCode::where('expires_at', '<', now())->delete();

        // Vivo = sin caducidad o con caducidad posterior al limite. Borrar el cliente deja los tokens a NULL (nullOnDelete)
        // y borra sus codigos (cascade).
        // La edad del cliente cuenta desde su alta o desde la ultima emision de un token (`last_used_at`), lo que sea mas
        // reciente: un cliente con un token revocado hoy no se borra esa misma noche.
        $clients = OAuthClient::where('created_at', '<', $limit)
            ->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', $limit))
            ->whereDoesntHave('tokens', fn ($q) => $q->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', $limit)))
            ->delete();

        $this->info("Clientes OAuth borrados: $clients · codigos caducados borrados: $codes");

        return self::SUCCESS;
    }
}
