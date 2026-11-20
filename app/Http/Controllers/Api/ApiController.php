<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidPostTransition;
use App\Exceptions\OwnershipException;
use App\Exceptions\PostStateConflict;
use App\Exceptions\PostValidationException;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\AuditLog;
use App\Services\Reports\TooManyActiveReports;
use App\Services\Studio\StudioAudioProbeUnavailable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use WeakMap;

/**
 * Base de los controladores REST de `api/v1`: marca del usuario con 404 uniforme, y una sola traduccion de los errores de dominio a JSON
 * (`{message, errors?}`: 422 validacion, 409 transicion invalida, 404 ajeno o inexistente) con su rastro de auditoria del canal `api`.
 * Los servicios son los mismos que usan la web y el MCP: aqui no hay logica de negocio.
 */
abstract class ApiController extends Controller
{
    /** @var WeakMap<Request, array<int, true>> Contexto ligado a la petición, incluso con controladores reutilizados. */
    private WeakMap $auditBrands;

    public function __construct(private readonly AuditLog $auditLog)
    {
        $this->auditBrands = new WeakMap;
    }

    /** La marca del usuario de la API key; una ajena responde igual que una inexistente. */
    protected function ownedBrand(Request $request, int $brand): Brand
    {
        $found = Brand::ownedBy($request->user()->id)->find($brand) ?? abort(404);
        $brands = $this->auditBrands[$request] ?? [];
        $brands[$found->id] = true;
        $this->auditBrands[$request] = $brands;

        return $found;
    }

    /**
     * Ejecuta la accion, audita el resultado y devuelve el JSON; los errores de dominio se traducen sin filtrar detalles internos.
     *
     * @param  callable(): array<string, mixed>  $run
     */
    protected function respond(Request $request, string $action, ?string $resource, int|string|null $id, callable $run, int $status = 200): JsonResponse
    {
        try {
            $result = $run();
        } catch (PostStateConflict|InvalidPostTransition|DomainException $e) {
            // Estado que no admite la operacion: 409. 422 queda solo para errores de entrada.
            $this->audit($request, $action, AuditLog::ERROR, $resource, $id);

            return response()->json(['message' => $e->getMessage()], 409);
        } catch (PostValidationException $e) {
            $this->audit($request, $action, AuditLog::ERROR, $resource, $id);

            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (ValidationException $e) {
            $this->audit($request, $action, AuditLog::ERROR, $resource, $id);

            throw $e;
        } catch (StudioAudioProbeUnavailable $e) {
            $this->audit($request, $action, AuditLog::ERROR, $resource, $id);

            return response()->json(['message' => $e->getMessage()], 503);
        } catch (TooManyActiveReports $e) {
            $this->audit($request, $action, AuditLog::ERROR, $resource, $id);

            return response()->json(['message' => $e->getMessage()], 429);
        } catch (InvalidArgumentException $e) {
            $this->audit($request, $action, AuditLog::ERROR, $resource, $id);

            return response()->json(['message' => $e->getMessage(), 'errors' => ['request' => [$e->getMessage()]]], 422);
        } catch (HttpExceptionInterface|OwnershipException $e) {
            $outcome = $e instanceof OwnershipException || $e->getStatusCode() === 404 ? AuditLog::DENIED : AuditLog::ERROR;
            $this->audit($request, $action, $outcome, $resource, $id);

            throw $e;
        }
        $this->audit($request, $action, AuditLog::OK, $resource, $result['id'] ?? $id);

        return response()->json($result, $status);
    }

    protected function audit(Request $request, string $action, string $outcome, ?string $resource, int|string|null $id, ?int $brandId = null): void
    {
        $brands = $this->auditBrands[$request] ?? [];
        $verifiedBrand = count($brands) === 1 ? array_key_first($brands) : null;
        $this->auditLog->record($request->user(), $action, $outcome, $resource, $id, null, 'api', brandId: $brandId ?? $verifiedBrand);
    }
}
