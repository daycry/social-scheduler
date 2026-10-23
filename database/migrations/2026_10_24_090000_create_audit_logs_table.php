<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro de uso/auditoria (MCP): quien (usuario + key), que herramienta, resultado. Nunca guarda el secreto.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->string('channel', 20)->default('mcp');
            $table->string('action', 80);
            $table->string('outcome', 12); // ok | denied | error
            $table->string('resource', 40)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('detail', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
