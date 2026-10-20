<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Correctora (revision intento 2, N2): la migracion 2026_11_02_090000 rellenaba `last_position`/`last_item_id` con `min(cursor, count) - 1`,
     * lo que en listas recicladas con cursor > count apuntaba al ultimo elemento y no a items[(cursor - 1) % count] (la semantica antigua
     * servia items[cursor % count]). Recalcula solo las recicladas con cursor > count; el cursor sigue avanzando en paralelo, asi que es seguro.
     */
    public function up(): void
    {
        DB::table('autolists')->where('recycle', true)->where('cursor', '>', 0)->orderBy('id')->each(function ($list) {
            $items = DB::table('autolist_items')->where('autolist_id', $list->id)->orderBy('position')->orderBy('id')->get(['id', 'position']);
            if ($items->count() === 0 || $list->cursor <= $items->count()) {
                return;
            }
            $last = $items[($list->cursor - 1) % $items->count()];
            DB::table('autolists')->where('id', $list->id)->update(['last_position' => $last->position, 'last_item_id' => $last->id]);
        });
    }

    public function down(): void {}
};
