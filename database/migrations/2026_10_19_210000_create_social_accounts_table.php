<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('network', 20);
            $table->string('external_id');
            $table->string('name');
            $table->string('username')->nullable();
            $table->string('status', 30)->default('activa');
            // Cifrados con el cast `encrypted` (Crypt/APP_KEY): en BD solo hay texto cifrado.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->timestamp('data_access_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('meta')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'network', 'external_id']);
            $table->index(['user_id', 'brand_id']);
            $table->index(['status', 'token_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
