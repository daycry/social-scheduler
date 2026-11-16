<?php

namespace App\Services\Studio;

use App\Models\Studio\DesignExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class StudioAvControl
{
    public function __construct(private readonly StudioAvLifecycle $lifecycle, private readonly StudioAvStorage $storage, private readonly StudioAvQueue $queue) {}

    public function cancel(User $user, DesignExport $export): DesignExport
    {
        $current = $this->lifecycle->cancel($user, $export);
        try {
            $this->storage->signalCancellation($current);
        } catch (Throwable) {
            abort(503, 'La cancelación está registrada, pero no se pudo señalizar el proceso. Consulta el estado o repite la solicitud.');
        }

        return $current;
    }

    public function retry(User $user, DesignExport $export): DesignExport
    {
        $current = $this->lifecycle->retry($user, $export);
        DB::afterCommit(fn () => $this->queue->enqueue($current->id));

        return $current->refresh();
    }
}
