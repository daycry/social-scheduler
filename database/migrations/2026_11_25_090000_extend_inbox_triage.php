<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // T-20: estado abierto/cerrado, asignacion, etiquetas y respuestas guardadas..
        Schema::table('inbox_comments', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('inbox_comment_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_comment_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40);
            $table->timestamps();

            $table->unique(['inbox_comment_id', 'label']);
            $table->index(['user_id', 'label']);
        });

        Schema::create('inbox_saved_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 60);
            $table->text('body');
            $table->timestamps();

            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_saved_replies');
        Schema::dropIfExists('inbox_comment_labels');
        Schema::table('inbox_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn('closed_at');
        });
    }
};
