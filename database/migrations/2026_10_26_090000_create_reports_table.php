<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('format', 8); // pdf | csv
            $table->date('from');
            $table->date('to');
            $table->string('compare', 10)->default('none');
            $table->string('status', 12)->default('pending'); // pending | processing | ready | failed
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
