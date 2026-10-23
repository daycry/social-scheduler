<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Competidores de Instagram (PA-08) vistos con Business Discovery desde una cuenta propia.
        Schema::create('competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            // Cuenta propia desde la que se consulta; si se borra, el competidor se conserva y el job usa otra activa de la marca.
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('username', 30);
            $table->string('name')->nullable();
            $table->string('profile_picture_url', 1000)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 200)->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'username']);
            $table->index('user_id');
        });

        Schema::create('competitor_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competitor_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('followers_count')->nullable();
            $table->unsignedInteger('media_count')->nullable();
            $table->timestamps();

            $table->unique(['competitor_id', 'date']);
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_snapshots');
        Schema::dropIfExists('competitors');
    }
};
