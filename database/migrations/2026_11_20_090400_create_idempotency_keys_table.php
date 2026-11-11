<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (T-19): claves de idempotencia por usuario y ambito (lotes). La respuesta se guarda para reproducirla.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 40);
            $table->string('key', 120);
            $table->string('request_hash', 64);
            $table->longText('response')->nullable(); // nulo = en curso
            $table->timestamps();

            $table->unique(['user_id', 'scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
