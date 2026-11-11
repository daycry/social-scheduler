<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\Canva\CanvaDesigns;
use App\Services\Canva\CanvaException;
use App\Services\Canva\CanvaIntegration;
use App\Services\Canva\CanvaJobs;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_canva')]
#[Description('Canva por marca (T-16). La conexion OAuth se hace en la app (aqui no se conecta ni se ven tokens). action=status: si Canva esta configurada y conectada, funciones disponibles segun el plan (cada desactivada lleva su motivo) y trabajos recientes. action=designs: lista o busca (query, continuation) los disenos de Canva de la marca, con enlace explicito «open_url» al editor de Canva. action=export: encola la exportacion de design_id a png, jpg o mp4 hacia la biblioteca de la marca (queda con origen Canva) o pdf (devuelve el enlace temporal de Canva); transparent=true solo en PNG si el plan lo permite. action=send: sube media_id de la biblioteca como asset de Canva. action=get: estado de job_id (queued, processing, importing, ready, failed). Nada compra ni publica. status/designs/get requieren canva:read; export/send, canva:write.')]
class ManageCanva extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::CANVA_READ;
    }

    protected function abilityFor(Request $request): string
    {
        return in_array($request->get('action'), ['export', 'send'], true) ? Abilities::CANVA_WRITE : Abilities::CANVA_READ;
    }

    protected function resource(): ?string
    {
        return 'brand';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->brandSchema($schema) + [
            'action' => $schema->string()->enum(['status', 'designs', 'export', 'send', 'get'])->required(),
            'query' => $schema->string()->description('Busqueda de disenos (designs)'),
            'continuation' => $schema->string()->description('Cursor de la pagina siguiente (designs)'),
            'design_id' => $schema->string()->description('Id de un diseño de Canva obtenido con designs (export)'),
            'format' => $schema->string()->enum(CanvaJobs::FORMATS)->description('Formato de exportacion (export)'),
            'title' => $schema->string()->description('Nombre del recurso importado (export)'),
            'transparent' => $schema->boolean()->description('PNG con fondo transparente (export, requiere plan de pago)'),
            'media_id' => $schema->integer()->min(1)->description('Recurso listo de la biblioteca (send)'),
            'job_id' => $schema->integer()->min(1)->description('Trabajo de esta marca (get)'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $action = (string) $request->get('action');
        if (! in_array($action, ['status', 'designs', 'export', 'send', 'get'], true)) {
            throw new InvalidArgumentException('action debe ser status, designs, export, send o get.');
        }
        $brand = $this->brandFrom($request, $user);
        $integration = app(CanvaIntegration::class);
        $jobs = app(CanvaJobs::class);

        try {
            return match ($action) {
                'status' => ['canva' => $integration->state($user, $brand)],
                'designs' => app(CanvaDesigns::class)->list($user, $brand, $integration->active($user, $brand), $this->text($request, 'query'), $this->text($request, 'continuation')),
                'export' => ['job' => $jobs->present($jobs->startExport(
                    $user, $brand, $integration->active($user, $brand), (string) $request->get('design_id'), (string) $request->get('format'),
                    ['title' => $this->text($request, 'title'), 'transparent' => filter_var($request->get('transparent'), FILTER_VALIDATE_BOOLEAN)],
                ))],
                'send' => ['job' => $jobs->present($jobs->startUpload($user, $brand, $integration->active($user, $brand), (int) $request->get('media_id')))],
                'get' => ['job' => $jobs->present($jobs->find($user, $brand, (int) $request->get('job_id')))],
            };
        } catch (CanvaException $e) {
            throw new InvalidArgumentException($e->getMessage());
        }
    }

    private function text(Request $request, string $key): ?string
    {
        $value = $request->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
