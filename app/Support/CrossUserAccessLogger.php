<?php

namespace App\Support;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registra `access.denied` cuando el route-model-binding falla (404) porque el
 * recurso existe pero pertenece a otro usuario. El cliente sigue recibiendo un
 * 404 identico al de un id inexistente: solo el log interno distingue ambos casos.
 */
class CrossUserAccessLogger
{
    public static function log(Throwable $e): void
    {
        $previous = $e instanceof ModelNotFoundException ? $e : $e->getPrevious();

        if (! $previous instanceof ModelNotFoundException || Auth::id() === null) {
            return;
        }

        $modelClass = $previous->getModel();

        if (! is_string($modelClass) || ! in_array(BelongsToUser::class, class_uses_recursive($modelClass), true)) {
            return;
        }

        foreach ($previous->getIds() as $id) {
            $ownerId = $modelClass::withoutUserScope()->whereKey($id)->value('user_id');

            if ($ownerId === null || (int) $ownerId === (int) Auth::id()) {
                continue;
            }

            Log::warning('access.denied', [
                'user_id' => Auth::id(),
                'resource' => strtolower(class_basename($modelClass)),
                strtolower(class_basename($modelClass)).'_id' => $id,
                'resource_id' => $id,
                'ability' => 'bind',
            ]);
        }
    }
}
