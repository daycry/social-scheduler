<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (C-01a): textos guardados por usuario; brand_id nulo = «Todas las marcas».
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_texts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // fragmento | hashtags
            $table->string('title', 120);
            $table->text('body');
            $table->timestamps();

            $table->index(['user_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_texts');
    }
};
