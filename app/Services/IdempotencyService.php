<?php

namespace App\Services;

use App\Exceptions\PostValidationException;
use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Idempotencia por clave de peticion (T-19, lotes). La clave es por usuario y ambito. Misma clave y mismo cuerpo = se devuelve la
 * respuesta guardada SIN volver a ejecutar; misma clave y otro cuerpo = 422; misma clave con la primera peticion aun en curso = 409.
 * Una ejecucion que falla libera la clave para poder reintentarla.
 */
class IdempotencyService
{
    public const TTL_HOURS = 24;

    /**
     * @param  array<string, mixed>  $payload  lo que identifica la peticion (accion, ids, parametros)
     * @param  callable(): array<string, mixed>  $run
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function run(User $user, string $scope, ?string $key, array $payload, callable $run): array
    {
        if ($key === null || $key === '') {
            return ['result' => $run(), 'replayed' => false];
        }
        if (strlen($key) > 120 || ! preg_match('/^[A-Za-z0-9._:\-]+$/', $key)) {
            throw new PostValidationException(['idempotency_key' => ['La clave de idempotencia debe tener hasta 120 caracteres (letras, numeros, . _ : -).']]);
        }

        $hash = hash('sha256', json_encode($this->canonical($payload), JSON_UNESCAPED_UNICODE));

        try {
            $row = IdempotencyKey::query()->create(['user_id' => $user->id, 'scope' => $scope, 'key' => $key, 'request_hash' => $hash]);
        } catch (QueryException) {
            $existing = IdempotencyKey::query()->where('user_id', $user->id)->where('scope', $scope)->where('key', $key)->first();
            if ($existing === null) {
                throw new PostValidationException(['idempotency_key' => ['No se pudo reservar la clave de idempotencia. Reintenta.']]);
            }
            if ($existing->request_hash !== $hash) {
                throw new PostValidationException(['idempotency_key' => ['Esta clave de idempotencia ya se uso con otra peticion.']]);
            }
            if ($existing->response === null) {
                abort(409, 'La peticion con esta clave de idempotencia sigue en curso.');
            }

            return ['result' => json_decode($existing->response, true), 'replayed' => true];
        }

        try {
            $result = $run();
        } catch (Throwable $e) {
            $row->delete();

            throw $e;
        }
        $row->forceFill(['response' => json_encode($result, JSON_UNESCAPED_UNICODE)])->save();

        return ['result' => $result, 'replayed' => false];
    }

    /** Borra las claves caducadas. */
    public function prune(): int
    {
        return IdempotencyKey::query()->where('created_at', '<', now()->subHours(self::TTL_HOURS))->delete();
    }

    private function canonical(mixed $v): mixed
    {
        if (is_array($v)) {
            $v = array_map(fn ($x) => $this->canonical($x), $v);
            if (! array_is_list($v)) {
                ksort($v);
            }
        }

        return $v;
    }
}
