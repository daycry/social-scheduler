<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Revision (B2): la franja se identifica por su fecha/hora LOCAL de la marca, no solo por el instante UTC; asi cambiar la zona
        // horaria de la marca no duplica las franjas ya generadas.
        Schema::table('autolist_slots', function (Blueprint $table) {
            $table->string('local_date', 10)->nullable()->after('slot_at');
            $table->string('local_time', 5)->nullable()->after('local_date');
            $table->unique(['autolist_id', 'local_date', 'local_time'], 'autolist_slots_local_unique');
        });

        // Revision (B9): el cursor apunta al ULTIMO elemento usado (posicion + id), no a un indice: borrar elementos no desplaza la cola.
        Schema::table('autolists', function (Blueprint $table) {
            $table->unsignedInteger('last_position')->nullable()->after('cursor');
            $table->unsignedBigInteger('last_item_id')->nullable()->after('last_position');
        });

        $zones = DB::table('autolists')->join('brands', 'brands.id', '=', 'autolists.brand_id')->pluck('brands.timezone', 'autolists.id');
        DB::table('autolist_slots')->orderBy('id')->each(function ($slot) use ($zones) {
            $tz = $zones[$slot->autolist_id] ?? 'UTC';
            try {
                $local = CarbonImmutable::parse($slot->slot_at, 'UTC')->setTimezone($tz ?: 'UTC');
            } catch (Throwable) {
                $local = CarbonImmutable::parse($slot->slot_at, 'UTC');
            }
            DB::table('autolist_slots')->where('id', $slot->id)->update(['local_date' => $local->format('Y-m-d'), 'local_time' => $local->format('H:i')]);
        });

        DB::table('autolists')->where('cursor', '>', 0)->orderBy('id')->each(function ($list) {
            $items = DB::table('autolist_items')->where('autolist_id', $list->id)->orderBy('position')->orderBy('id')->get(['id', 'position']);
            $last = $items->count() > 0 ? $items[(min($list->cursor, $items->count()) - 1) % $items->count()] : null;
            if ($last) {
                DB::table('autolists')->where('id', $list->id)->update(['last_position' => $last->position, 'last_item_id' => $last->id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('autolists', function (Blueprint $table) {
            $table->dropColumn(['last_position', 'last_item_id']);
        });
        Schema::table('autolist_slots', function (Blueprint $table) {
            $table->dropUnique('autolist_slots_local_unique');
            $table->dropColumn(['local_date', 'local_time']);
        });
    }
};
