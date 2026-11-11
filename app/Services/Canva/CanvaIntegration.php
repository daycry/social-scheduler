<?php

namespace App\Services\Canva;

use App\Models\Brand;
use App\Models\Canva\CanvaConnection;
use App\Models\User;

/** Estado de la integracion con Canva de una marca y su conexion activa (T-16). Lo comparten la web, la API y el MCP. */
class CanvaIntegration
{
    public function __construct(private readonly CanvaConnections $connections, private readonly CanvaJobs $jobs) {}

    /** @return array<string, mixed> */
    public function state(User $user, Brand $brand): array
    {
        $configured = CanvaClient::configured();
        $connection = $configured ? $this->connections->find($user, $brand) : null;
        $base = ['configured' => $configured, 'connected' => false, 'status' => $configured ? 'disconnected' : 'not_configured', 'name' => null, 'features' => [], 'capabilities_known' => false, 'formats' => [], 'jobs' => []];
        if ($connection === null) {
            return $base;
        }
        if ($connection->status !== 'active') {
            return ['status' => 'expired', 'name' => $connection->display_name] + $base;
        }
        $caps = [];
        $known = false;
        try {
            $caps = $this->connections->capabilities($connection);
            $known = $connection->capabilities_at !== null;
        } catch (CanvaException) {
            // Sin capacidades conocidas las funciones de plan se ofrecen desactivadas; el resto sigue funcionando.
        }

        return [
            'connected' => true, 'status' => 'active', 'name' => $connection->display_name, 'capabilities_known' => $known,
            'features' => CanvaCapabilities::features($caps, array_values((array) $connection->scopes)),
            'formats' => collect(CanvaDesigns::FORMATS)->map(fn ($f, $key) => ['key' => $key] + $f)->values()->all(),
            'jobs' => array_map(fn ($j) => $this->jobs->present($j), $this->jobs->recent($user, $brand)),
        ] + $base;
    }

    /** La conexion activa de la marca o un `CanvaException` explicando por que no la hay. */
    public function active(User $user, Brand $brand): CanvaConnection
    {
        if (! CanvaClient::configured()) {
            throw new CanvaException('La integración con Canva no está configurada en esta instalación.', 404, 'not_configured');
        }
        $connection = $this->connections->find($user, $brand);
        if ($connection === null) {
            throw new CanvaException('Esta marca no está conectada a Canva.', 409, 'not_connected');
        }
        if ($connection->status !== 'active') {
            throw new CanvaException('La conexión con Canva ha caducado. Vuelve a conectar la cuenta.', 401, 'unauthorized');
        }

        return $connection;
    }
}
