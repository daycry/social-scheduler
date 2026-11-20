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

        $token = Str::random(40);
        Cache::put($this->key($request, $brand, $token), $rows, now()->addMinutes((int) config('post_import.ttl_minutes')));
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

        $rows = Cache::get($key);
        if ($rows === null) {
            return response()->json(['message' => 'La importación ha caducado o no existe: sube el fichero otra vez.'], 410);
        }
        // `true` es la marca de «ya confirmada»; `add` es atomico: de dos confirmaciones simultaneas solo una pasa.
        if (! is_array($rows) || ! Cache::add($key.':claimed', true, $ttl)) {
            return response()->json(['message' => 'Esta importación ya se ha confirmado: no se vuelve a crear nada.'], 409);
        }

        $results = $this->import->import($request->user(), $brand, $rows);
        Cache::put($key, true, $ttl); // las filas ya no hacen falta: queda solo la marca de confirmada
        $created = count(array_filter($results, fn (array $r) => $r['post_id'] !== null));

        return response()->json(['summary' => ['created' => $created, 'skipped' => count($results) - $created], 'rows' => $results]);
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
