<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_designs', function (Blueprint $table) {
            $table->string('approval_status', 16)->nullable()->index();
            $table->unsignedInteger('approval_revision')->nullable();
            $table->string('approval_signature', 40)->nullable();
            $table->text('approval_comment')->nullable();
            $table->timestamp('approval_requested_at')->nullable();
            $table->timestamp('approval_decided_at')->nullable();
            $table->foreignId('approval_decided_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('studio_designs', function (Blueprint $table) {
            $table->dropForeign(['approval_decided_by']);
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'approval_revision', 'approval_signature', 'approval_comment', 'approval_requested_at', 'approval_decided_at', 'approval_decided_by']);
        });
    }
};
