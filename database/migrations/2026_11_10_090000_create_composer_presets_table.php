<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (C-04): interruptores recordados por (marca, red). Solo booleanos; los campos de texto no persisten.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('composer_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('network', 20);
            $table->json('options');
            $table->timestamps();

            $table->unique(['brand_id', 'network']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composer_presets');
    }
};
