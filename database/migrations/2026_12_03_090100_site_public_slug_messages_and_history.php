<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** T-15 (revision): slug publico separado del borrador, historial de slugs reservados y lectura de mensajes. Portable MySQL/SQLite. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('public_slug', 40)->nullable()->unique();
            $table->timestamp('messages_full_notified_at')->nullable();
        });
        // Lo ya publicado conserva su direccion actual como direccion publica.
        DB::table('sites')->whereNotNull('published')->update(['public_slug' => DB::raw('slug')]);

        Schema::create('site_slug_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 40)->unique();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('site_messages', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_messages', fn (Blueprint $table) => $table->dropColumn('read_at'));
        Schema::dropIfExists('site_slug_history');
        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique(['public_slug']);
            $table->dropColumn(['public_slug', 'messages_full_notified_at']);
        });
    }
};
