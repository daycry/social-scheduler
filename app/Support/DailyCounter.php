<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Increments a counter for a caller-supplied unique key, optionally including a date. */
final class DailyCounter
{
    /** @param array<string, int|string> $key Columns backed by a unique constraint. */
    public function increment(string $table, array $key): void
    {
        $counter = fn () => DB::table($table)->where($key);
        if ($counter()->increment('count') > 0) {
            return;
        }

        try {
            DB::table($table)->insert($key + ['count' => 1]);
        } catch (QueryException) {
            // Preserve the retry when the bucket or its parent changes concurrently.
            $counter()->increment('count');
        }
    }
}
