<?php

namespace App\Http\Controllers\Canva;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Canva\CanvaConnection;
use App\Services\Canva\CanvaBrandTemplates;
use App\Services\Canva\CanvaConnections;
use App\Services\Canva\CanvaDesigns;
use App\Services\Canva\CanvaException;
use App\Services\Canva\CanvaIntegration;
use App\Services\Canva\CanvaJobs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integracion oficial con Canva (T-16) para la web y la API REST (mismo servicio). La conexion OAuth solo se inicia desde la web; la API
 * consulta estado y disenos y lanza exportaciones/envios. Nada compra ni publica: lo importado es un recurso mas de la biblioteca.
 */
class CanvaController extends Controller
{
    public function __construct(
        private readonly CanvaConnections $connections,
        private readonly CanvaDesigns $designs,
        private readonly CanvaJobs $jobs,
        private readonly CanvaBrandTemplates $templates,
        private readonly CanvaIntegration $integration,
    ) {}

    public function index(Request $request, Brand $brand): InertiaResponse
    {
        $this->owned($request, $brand);

        return Inertia::render('Canva/Index', [
            'brand' => ['id' => $brand->id, 'name' => $brand->name],
            'canva' => $this->state($request, $brand),
            'flash' => ['success' => $request->session()->get('success'), 'error' => $request->session()->get('error')],
            'focus' => preg_match('/^[A-Za-z0-9_-]{4,40}$/', (string) $request->query('design')) ? (string) $request->query('design') : null,
        ]);
    }

    /** Estado de la integracion de la marca (JSON): web y API. */
    public function status(Request $request, Brand $brand): JsonResponse
    {
        $this->owned($request, $brand);

        return response()->json($this->state($request, $brand));
    }

    public function connect(Request $request, Brand $brand): Response
    {
        $this->owned($request, $brand);

        try {
            $url = $this->connections->start($request->user(), $brand, route('canva.callback'));
        } catch (CanvaException $e) {
            return redirect()->route('canva.index', $brand)->with('error', $e->getMessage());
        }

        // Peticion Inertia: visita completa a Canva; peticion normal: 302.
        return Inertia::location($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $user = $request->user();
        $attempt = $this->connections->take($user, (string) $request->query('state', ''));
        if ($attempt === null) {
            Log::warning('canva.invalid_state', ['user_id' => $user->id]);

            return redirect()->route('brands.index')->with('error', 'La sesión de conexión con Canva ha caducado o no es válida. Vuelve a intentarlo.');
        }
        $brand = Brand::withoutUserScope()->where('user_id', $user->id)->find($attempt['brand_id']);
        abort_if($brand === null, 404);
        $back = fn (string $type, string $message): RedirectResponse => redirect()->route('canva.index', $brand)->with($type, $message);

        if ($request->query('error')) {
            return $back('error', 'La autorización fue cancelada o rechazada en Canva. No se ha conectado nada.');
        }
        $code = (string) $request->query('code', '');
        if ($code === '') {
            return $back('error', 'Canva no devolvió ningún código de autorización.');
        }

        try {
            $connection = $this->connections->complete($user, $brand, $code, $attempt['verifier'], route('canva.callback'));
        } catch (CanvaException $e) {
            return $back('error', $e->getMessage());
        }

        return $back('success', 'Canva conectado'.($connection->display_name ? ': '.$connection->display_name : '').'.');
    }

    /** Vuelta desde el editor de Canva (Return URL): lleva al diseño si el `correlation_state` firmado vale para este usuario y marca. */
    public function return(Request $request): RedirectResponse
    {
        $resolved = $this->designs->resolveReturn($request->user(), (string) $request->query('correlation_jwt', ''));
        if ($resolved === null) {
            Log::warning('canva.invalid_return', ['user_id' => $request->user()->id]);

            return redirect()->route('brands.index')->with('error', 'El retorno desde Canva no es válido o ha caducado.');
        }

        return redirect()->route('canva.index', ['brand' => $resolved['brand'], 'design' => $resolved['design_id']]);
    }

    public function disconnect(Request $request, Brand $brand): JsonResponse|RedirectResponse
    {
        $this->owned($request, $brand);
        $this->connections->disconnect($request->user(), $brand);

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json($this->state($request, $brand))
            : redirect()->route('canva.index', $brand)->with('success', 'Canva desconectado. Se han borrado las credenciales.');
    }

    public function designs(Request $request, Brand $brand): JsonResponse
    {
        $this->owned($request, $brand);
        $data = $request->validate(['query' => ['nullable', 'string', 'max:255'], 'continuation' => ['nullable', 'string', 'max:500']]);

        return $this->guard(fn () => response()->json($this->designs->list($request->user(), $brand, $this->active($request, $brand), $data['query'] ?? null, $data['continuation'] ?? null)));
    }

    public function createDesign(Request $request, Brand $brand): JsonResponse
    {
        $this->owned($request, $brand);
        $data = $request->validate(['format' => ['required', 'string', 'in:'.implode(',', array_keys(CanvaDesigns::FORMATS))], 'title' => ['nullable', 'string', 'max:255']]);

        return $this->guard(fn () => response()->json(['design' => $this->designs->create($request->user(), $brand, $this->active($request, $brand), $data['format'], $data['title'] ?? null)], 201));
    }

    public function showDesign(Request $request, Brand $brand, string $design): JsonResponse
    {
        $this->owned($request, $brand);

        return $this->guard(fn () => response()->json(['design' => $this->designs->get($request->user(), $brand, $this->active($request, $brand), $design)]));
    }

    public function storeExport(Request $request, Brand $brand): JsonResponse
    {
        $this->owned($request, $brand);
        $data = $request->validate([
            'design_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{4,40}$/'],
            'format' => ['required', 'string', 'in:'.implode(',', CanvaJobs::FORMATS)],
            'title' => ['nullable', 'string', 'max:255'],
            'transparent' => ['nullable', 'boolean'],
        ]);

        return $this->guard(fn () => response()->json(['job' => $this->jobs->present(
            $this->jobs->startExport($request->user(), $brand, $this->active($request, $brand), $data['design_id'], $data['format'], ['title' => $data['title'] ?? null, 'transparent' => (bool) ($data['transparent'] ?? false)]),
        )], 202));
    }

    public function storeUpload(Request $request, Brand $brand): JsonResponse
    {
        $this->owned($request, $brand);
        $data = $request->validate(['media_id' => ['required', 'integer', 'min:1']]);

        return $this->guard(fn () => response()->json(['job' => $this->jobs->present(
            $this->jobs->startUpload($request->user(), $brand, $this->active($request, $brand), (int) $data['media_id']),
        )], 202));
    }

    public function templates(Request $request, Brand $brand): JsonResponse
    {
        $this->owned($request, $brand);
        $data = $request->validate(['query' => ['nullable', 'string', 'max:255'], 'continuation' => ['nullable', 'string', 'max:500']]);

        return $this->guard(fn () => response()->json($this->templates->list($this->active($request, $brand), $data['query'] ?? null, $data['continuation'] ?? null)));
    }

    public function templateDataset(Request $request, Brand $brand, string $template): JsonResponse
    {
        $this->owned($request, $brand);

        return $this->guard(fn () => response()->json(['fields' => $this->templates->dataset($this->active($request, $brand), $template)]));
    }

    public function storeAutofill(Request $request, Brand $brand): JsonResponse
    {
        $this->owned($request, $brand);
        $data = $request->validate([
            'brand_template_id' => ['required', 'string', 'regex:'.CanvaBrandTemplates::ID_PATTERN],
            'fields' => ['required', 'array', 'min:1', 'max:50'],
            'fields.*' => ['nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->guard(fn () => response()->json(['job' => $this->jobs->present(
            $this->jobs->startAutofill($request->user(), $brand, $this->active($request, $brand), $data['brand_template_id'], $data['fields'], $data['title'] ?? null),
        )], 202));
    }

    public function showJob(Request $request, Brand $brand, int $job): JsonResponse
    {
        $this->owned($request, $brand);

        return response()->json(['job' => $this->jobs->present($this->jobs->find($request->user(), $brand, $job))]);
    }

    /** @return array<string, mixed> */
    private function state(Request $request, Brand $brand): array
    {
        return $this->integration->state($request->user(), $brand);
    }

    private function owned(Request $request, Brand $brand): void
    {
        abort_unless((int) $brand->user_id === (int) $request->user()->id, 404);
    }

    private function active(Request $request, Brand $brand): CanvaConnection
    {
        return $this->integration->active($request->user(), $brand);
    }

    private function guard(callable $call): JsonResponse
    {
        try {
            return $call();
        } catch (CanvaException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status === 401 ? 409 : $e->status);
        }
    }
}
