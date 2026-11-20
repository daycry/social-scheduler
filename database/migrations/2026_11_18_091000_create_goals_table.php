<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            // Borrar una cuenta borra su objetivo; nunca lo convierte en un objetivo de toda la marca.
            $table->foreignId('social_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('kind', 24);
            $table->string('metric', 24)->nullable();
            $table->decimal('threshold', 20, 4);
            $table->boolean('enabled')->default(true);
            $table->boolean('mail_enabled')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'brand_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
