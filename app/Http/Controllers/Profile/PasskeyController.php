<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\PasskeyNameRequest;
use App\Services\AuditLog;
use App\Services\Passkeys\PasskeyFailure;
use App\Services\Passkeys\PasskeyGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Gestion de passkeys desde el perfil. Todas las rutas van tras `auth` + `password.confirm` (routes/auth.php).
 * `options` y `store` las llama el cliente `@laravel/passkeys` por fetch (JSON); `update` y `destroy` van por Inertia.
 * Toda busqueda parte de la relacion del usuario autenticado: una passkey ajena es un 404 (CA-19), igual que una
 * inexistente. El paquete tiene su propio `Route::bind('passkey')` global, por eso el parametro se llama `id`.
 */
class PasskeyController extends Controller
{
    public function __construct(private readonly PasskeyGateway $passkeys, private readonly AuditLog $audit) {}

    /**
     * Pide la contrasena (la ruta va tras `password.confirm`) y vuelve al perfil. Asi la UI confirma ANTES de abrir el
     * dialogo del navegador y el fetch de las opciones nunca ve un 423. `?then=add` abre el formulario de alta al volver.
     */
    public function confirm(Request $request): RedirectResponse
    {
        return redirect()->route('profile.edit', $request->query('then') === 'add' ? ['passkey' => 'add'] : []);
    }

    /** Opciones de alta (excluye los autenticadores ya registrados). */
    public function options(Request $request): JsonResponse
    {
        return response()->json(['options' => $this->passkeys->registrationOptions($request->user())]);
    }

    /** Valida la attestation y guarda la passkey con el nombre elegido. */
    public function store(PasskeyNameRequest $request): JsonResponse
    {
        $credential = $request->validate([
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
        ])['credential'];

        try {
            $passkey = $this->passkeys->register($request->user(), $request->validated('name'), $credential);
        } catch (PasskeyFailure $e) {
            $this->audit->record($request->user(), 'auth.passkey.register_failed', AuditLog::DENIED, 'user', $request->user()->id, $e->reason, 'web');

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $passkey->id, 'name' => $passkey->name], 201);
    }

    /** Renombra una passkey propia. */
    public function update(PasskeyNameRequest $request, int $id): RedirectResponse|JsonResponse
    {
        $passkey = $request->user()->passkeys()->findOrFail($id);
        $passkey->forceFill(['name' => $request->validated('name')])->save();

        // El nombre lo pone el usuario: no se guarda en la auditoria, solo que se renombro.
        $this->audit->record($request->user(), 'auth.passkey.renamed', AuditLog::OK, 'passkey', $passkey->id, null, 'web');

        return $request->wantsJson()
            ? response()->json(['id' => $passkey->id, 'name' => $passkey->name])
            : back()->with('status', 'passkey-renamed');
    }

    /** Borra una passkey propia: deja de servir para entrar. */
    public function destroy(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $passkey = $request->user()->passkeys()->findOrFail($id);
        $this->passkeys->delete($request->user(), $passkey);

        return $request->wantsJson()
            ? response()->json(['deleted' => true])
            : back()->with('status', 'passkey-deleted');
    }
}
