<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SmartLinks (PA-07): un microsite «link in bio» por marca.
        Schema::create('smart_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug', 40)->unique();
            $table->string('title', 100);
            $table->string('bio', 300)->nullable();
            $table->foreignId('avatar_media_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('theme_color', 7)->default('#4f46e5');
            $table->string('theme_mode', 5)->default('light');
            $table->boolean('published')->default(true);
            $table->timestamps();
        });

        Schema::create('smart_link_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('smart_link_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('type', 10); // link | header
            $table->string('label', 100);
            $table->string('url', 2000)->nullable();
            $table->timestamps();

            $table->index(['smart_link_id', 'position']);
        });

        // Contadores diarios AGREGADOS: ni IP, ni agente, ni identificadores (sin datos personales).
        Schema::create('smart_link_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('smart_link_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('count')->default(0);

            $table->unique(['smart_link_id', 'date']);
        });

        Schema::create('smart_link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('smart_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('smart_link_block_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('count')->default(0);

            $table->unique(['smart_link_block_id', 'date']);
            $table->index(['smart_link_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_link_clicks');
        Schema::dropIfExists('smart_link_views');
        Schema::dropIfExists('smart_link_blocks');
        Schema::dropIfExists('smart_links');
    }
};
