<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Retención de tablas sin modelo propio (A-08). */
class Retention
{
    /** Borra por lotes las notificaciones leídas con más de `retention.notifications_days`; devuelve cuántas. */
    public static function pruneNotifications(int $chunk = 1000): int
    {
        $days = (int) config('retention.notifications_days');
        if ($days <= 0) {
            return 0;
        }
        $total = 0;
        do {
            $ids = DB::table('notifications')->whereNotNull('read_at')->where('read_at', '<', now()->subDays($days))->limit($chunk)->pluck('id');
            $count = $ids->isEmpty() ? 0 : DB::table('notifications')->whereIn('id', $ids)->delete();
            $total += $count;
        } while ($count > 0);

        return $total;
    }
}
