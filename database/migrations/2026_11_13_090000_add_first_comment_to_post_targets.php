<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (C-01b, A1): primer comentario por destino. Todo nulo; el estado del comentario no toca `status` del destino.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->text('first_comment')->nullable();
            $table->string('first_comment_status', 20)->nullable();
            $table->unsignedSmallInteger('first_comment_attempts')->nullable();
            $table->string('first_comment_external_id', 191)->nullable();
            $table->string('first_comment_error', 500)->nullable();
            $table->timestamp('first_comment_at')->nullable();
            $table->index(['first_comment_status', 'first_comment_at'], 'post_targets_first_comment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropIndex('post_targets_first_comment_idx');
            $table->dropColumn(['first_comment', 'first_comment_status', 'first_comment_attempts', 'first_comment_external_id', 'first_comment_error', 'first_comment_at']);
        });
    }
};
