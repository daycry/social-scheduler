<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\SocialAccount;
use App\Services\Posts\PostCsvImport;
use App\Services\SocialAccountService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importacion de publicaciones por CSV (T-83): subir -> vista previa fila a fila (no crea nada) -> confirmar.
 *
 * La vista previa guarda las filas leidas en cache bajo un token aleatorio de un solo uso, ligado al usuario y a la marca y con caducidad.
 * Confirmar RECLAMA el token con `Cache::add` (atomico: solo UNA peticion lo consigue) antes de crear nada, asi un doble clic o un reenvio
 * recibe un 409 en vez de duplicar posts. Las filas se vuelven a validar al confirmar.
 */
class PostImportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly PostCsvImport $import, private readonly SocialAccountService $accounts) {}

    public function show(Request $request, Brand $brand): Response
    {
        $this->authorize('update', $brand);

        return Inertia::render('Posts/Import', [
            'brand' => $brand->only(['id', 'name', 'timezone']),
            'limits' => ['max_rows' => (int) config('post_import.max_rows'), 'max_kb' => (int) config('post_import.max_kb')],
            'columns' => PostCsvImport::COLUMNS,
            'required' => PostCsvImport::REQUIRED,
            'accounts' => $this->accounts->listForBrand($request->user(), $brand)->map(fn (SocialAccount $a) => [
                'id' => $a->id,
                'network' => $a->network->value,
                'network_label' => $a->network->label(),
                'name' => $a->name,
                'identifier' => PostCsvImport::identifier($a),
            ])->values(),
        ]);
    }

    public function preview(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $request->validate(
            ['file' => ['required', 'file', 'extensions:csv,txt', 'max:'.(int) config('post_import.max_kb')]],
            [
                'file.required' => 'Elige un fichero CSV.',
                'file.file' => 'No se pudo leer el fichero.',
                'file.uploaded' => 'No se pudo subir el fichero.',
                'file.extensions' => 'El fichero debe ser un CSV.',
                'file.max' => 'El fichero supera el tamaño máximo de '.(int) config('post_import.max_kb').' KB.',
            ],
        );

        $rows = $this->import->parse((string) file_get_contents($request->file('file')->getRealPath()));
        $preview = $this->import->preview($request->user(), $brand, $rows);

        // Una sola vista previa viva por usuario y marca: la nueva sustituye a las FILAS de la anterior. Nunca se tocan su marca `:claimed` ni su
        // avance: si su confirmacion sigue en curso (o murio por un timeout), un reintento debe seguir viendo que ya se reclamo y no duplicar.
        $token = Str::random(40);
        $current = $this->key($request, $brand, 'current');
        if (is_string($previous = Cache::get($current))) {
            Cache::forget($this->key($request, $brand, $previous));
        }
        $ttl = now()->addMinutes((int) config('post_import.ttl_minutes'));
        Cache::put($this->key($request, $brand, $token), ['rows' => $rows], $ttl);
        Cache::put($current, $token, $ttl);
        $valid = count(array_filter($preview, fn (array $r) => $r['valid']));

        return response()->json([
            'token' => $token,
            'summary' => ['total' => count($preview), 'valid' => $valid, 'invalid' => count($preview) - $valid],
            'rows' => $preview,
        ]);
    }

    public function confirm(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $token = $request->validate(['token' => ['required', 'string', 'size:40', 'alpha_num:ascii']])['token'];
        $key = $this->key($request, $brand, $token);
        $ttl = now()->addMinutes((int) config('post_import.ttl_minutes'));

        // Ya confirmada: el reintento recibe el mismo resultado, sin crear nada. El avance vive en su propia clave (pequena) y sobrevive a
        // que una vista previa nueva sustituya las filas.
        $progress = Cache::get($key.':progress');
        if (is_array($progress) && $progress['done']) {
            return $this->outcome($progress['results'], true);
        }
        $state = Cache::get($key);
        if (! is_array($state) && ! is_array($progress)) {
            return response()->json(['message' => 'La importación ha caducado o no existe: sube el fichero otra vez.'], 410);
        }
        // `add` es atomico: de dos confirmaciones simultaneas (o de un reintento tras una interrumpida) solo una pasa.
        if (! is_array($state) || ! Cache::add($key.':claimed', true, $ttl)) {
            return response()->json([
                'message' => 'Esta importación ya se ha confirmado y está en curso o se interrumpió: no se vuelve a crear nada. Estas son las filas ya procesadas.',
                'rows' => $progress['results'] ?? [],
            ], 409);
        }

        $results = $this->import->import($request->user(), $brand, $state['rows'], fn (array $done) => Cache::put($key.':progress', ['results' => $done, 'done' => false], $ttl));
        Cache::put($key.':progress', ['results' => $results, 'done' => true], $ttl);
        Cache::forget($key); // las filas ya no hacen falta: queda el resultado

        return $this->outcome($results, false);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function outcome(array $results, bool $repeated): JsonResponse
    {
        $created = count(array_filter($results, fn (array $r) => $r['post_id'] !== null));

        return response()->json(['summary' => ['created' => $created, 'skipped' => count($results) - $created], 'rows' => $results, 'repeated' => $repeated]);
    }

    public function sample(Request $request, Brand $brand): StreamedResponse
    {
        $this->authorize('update', $brand);
        $csv = $this->import->sample($request->user(), $brand);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'importar-publicaciones.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function key(Request $request, Brand $brand, string $token): string
    {
        return 'post-import:'.$request->user()->id.':'.$brand->id.':'.$token;
    }
}
