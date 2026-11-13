<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidPostTransition;
use App\Exceptions\PostValidationException;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\AuditLog;
use App\Services\Reports\TooManyActiveReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Base de los controladores REST de `api/v1`: marca del usuario con 404 uniforme, y una sola traduccion de los errores de dominio a JSON
 * (`{message, errors?}`: 422 validacion, 409 transicion invalida, 404 ajeno o inexistente) con su rastro de auditoria del canal `api`.
 * Los servicios son los mismos que usan la web y el MCP: aqui no hay logica de negocio.
 */
abstract class ApiController extends Controller
{
    /** La marca del usuario de la API key; una ajena responde igual que una inexistente. */
    protected function ownedBrand(Request $request, int $brand): Brand
    {
        return Brand::withoutUserScope()->where('user_id', $request->user()->id)->find($brand) ?? abort(404);
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
        } catch (PostValidationException $e) {
            $this->audit($request, $action, AuditLog::ERROR, $resource, $id);

            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (InvalidPostTransition $e) {
            $this->audit($request, $action, AuditLog::ERROR, $resource, $id);

            return response()->json(['message' => $e->getMessage()], 409);
        } catch (TooManyActiveReports $e) {
            $this->audit($request, $action, AuditLog::ERROR, $resource, $id);

            return response()->json(['message' => $e->getMessage()], 429);
        } catch (InvalidArgumentException $e) {
            $this->audit($request, $action, AuditLog::ERROR, $resource, $id);

            return response()->json(['message' => $e->getMessage(), 'errors' => ['request' => [$e->getMessage()]]], 422);
        } catch (HttpExceptionInterface $e) {
            $this->audit($request, $action, $e->getStatusCode() === 404 ? AuditLog::DENIED : AuditLog::ERROR, $resource, $id);

            throw $e;
        }
        $this->audit($request, $action, AuditLog::OK, $resource, $result['id'] ?? $id);

        return response()->json($result, $status);
    }

    protected function audit(Request $request, string $action, string $outcome, ?string $resource, int|string|null $id): void
    {
        app(AuditLog::class)->record($request->user(), $action, $outcome, $resource, $id, null, 'api');
    }
}
