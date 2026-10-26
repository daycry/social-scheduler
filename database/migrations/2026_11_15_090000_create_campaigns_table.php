<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (C-03): campañas por marca (D3). Se archivan, no se ocultan por borrado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('color', 7)->default('#4f46e5');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
