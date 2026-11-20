<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->decimal('last_value', 20, 4)->nullable();
            $table->date('last_period')->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('last_expires_at')->nullable();
        });
        Schema::create('goal_alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->string('period_key', 100);
            $table->json('payload');
            $table->timestamps();
            $table->unique(['goal_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_alert_events');
        Schema::table('goals', fn (Blueprint $table) => $table->dropColumn(['last_value', 'last_period', 'last_evaluated_at', 'last_expires_at']));
    }
};
