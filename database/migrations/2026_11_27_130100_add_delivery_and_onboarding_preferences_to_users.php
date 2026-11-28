<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_channels')->nullable();
            $table->boolean('single_key_shortcuts')->default(true);
            $table->string('onboarding_step', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['notification_channels', 'single_key_shortcuts', 'onboarding_step']));
    }
};
