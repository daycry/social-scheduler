<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Canva\CanvaConnection;
use App\Models\User;
use App\Services\Canva\CanvaBrandTemplates;
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
#[Description('Canva por marca (T-16). La conexion OAuth se hace en la app (aqui no se conecta ni se ven tokens). action=status: si Canva esta configurada y conectada, funciones disponibles segun el plan (cada desactivada lleva su motivo) y trabajos recientes. action=designs: lista o busca (query, continuation) los disenos de Canva de la marca, con enlace explicito «open_url» al editor de Canva. action=export: encola la exportacion de design_id a png, jpg, mp4 o pdf hacia la biblioteca de la marca (queda con origen Canva; un PDF entra como documento: se ve y se descarga en la app, pero NO es media publicable en redes); transparent=true solo en PNG si el plan lo permite. action=templates: lista las plantillas de marca (query, continuation) o, con template_id, sus campos (solo los de texto se rellenan); requiere un plan con plantillas de marca. action=autofill: crea un diseño nuevo rellenando template_id con fields (nombre => texto; opcional title); requiere Autofill en el plan; devuelve un trabajo (consultalo con get; al terminar trae design_id). action=send: sube media_id de la biblioteca como asset de Canva. action=get: estado de job_id (queued, processing, importing, ready, failed). Nada compra ni publica. status/designs/get requieren canva:read; export/send/autofill, canva:write.')]
class ManageCanva extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::CANVA_READ;
    }

    protected function abilityFor(Request $request): string
    {
        return in_array($request->get('action'), ['export', 'send', 'autofill'], true) ? Abilities::CANVA_WRITE : Abilities::CANVA_READ;
    }

    protected function resource(): ?string
    {
        return 'brand';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->brandSchema($schema) + [
            'action' => $schema->string()->enum(['status', 'designs', 'templates', 'export', 'send', 'autofill', 'get'])->required(),
            'query' => $schema->string()->description('Busqueda de disenos (designs)'),
            'continuation' => $schema->string()->description('Cursor de la pagina siguiente (designs)'),
            'design_id' => $schema->string()->description('Id de un diseño de Canva obtenido con designs (export)'),
            'format' => $schema->string()->enum(CanvaJobs::FORMATS)->description('Formato de exportacion (export)'),
            'title' => $schema->string()->description('Nombre del recurso importado (export)'),
            'transparent' => $schema->boolean()->description('PNG con fondo transparente (export, requiere plan de pago)'),
            'media_id' => $schema->integer()->min(1)->description('Recurso listo de la biblioteca (send)'),
            'job_id' => $schema->integer()->min(1)->description('Trabajo de esta marca (get)'),
            'template_id' => $schema->string()->description('Id de una plantilla de marca obtenido con templates (templates: sus campos; autofill: la plantilla a rellenar)'),
            'fields' => $schema->object()->description('Texto de cada campo de la plantilla: nombre => texto (autofill)'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $action = (string) $request->get('action');
        if (! in_array($action, ['status', 'designs', 'templates', 'export', 'send', 'autofill', 'get'], true)) {
            throw new InvalidArgumentException('action debe ser status, designs, templates, export, send, autofill o get.');
        }
        $brand = $this->brandFrom($request, $user);
        $integration = app(CanvaIntegration::class);
        $jobs = app(CanvaJobs::class);

        try {
            return match ($action) {
                'status' => ['canva' => $integration->state($user, $brand)],
                'designs' => app(CanvaDesigns::class)->list($user, $brand, $integration->active($user, $brand), $this->text($request, 'query'), $this->text($request, 'continuation')),
                'templates' => $this->templates($request, $integration->active($user, $brand)),
                'autofill' => ['job' => $jobs->present($jobs->startAutofill(
                    $user, $brand, $integration->active($user, $brand), (string) $request->get('template_id'), (array) $request->get('fields'), $this->text($request, 'title'),
                ))],
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

    /** @return array<string, mixed> */
    private function templates(Request $request, CanvaConnection $connection): array
    {
        $templates = app(CanvaBrandTemplates::class);
        $id = $this->text($request, 'template_id');

        return $id !== null
            ? ['fields' => $templates->dataset($connection, $id)]
            : $templates->list($connection, $this->text($request, 'query'), $this->text($request, 'continuation'));
    }

    private function text(Request $request, string $key): ?string
    {
        $value = $request->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
