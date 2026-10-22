<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| WebAuthn permite credential IDs de hasta 1023 bytes (1364 caracteres en base64url sin relleno); la columna era
| string(255). En MySQL, 1400 caracteres en utf8mb4 (4 bytes) son 5600 bytes y no caben en un indice unico (limite de
| 3072 bytes con InnoDB), asi que la columna pasa a ascii (1 byte por caracter, 1400 bytes) y a `ascii_bin`: el
| base64url distingue mayusculas de minusculas, y la collation por defecto (_ci) podria tratar dos IDs distintos como
| el mismo. El indice unico se conserva.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passkeys', function (Blueprint $table) {
            $column = $table->string('credential_id', 1400);

            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $column->charset('ascii')->collation('ascii_bin');
            }

            $column->change();
        });
    }

    public function down(): void
    {
        // No se estrecha: un ID largo ya guardado no cabria en 255 y el unico fallaria.
    }
};
