<?php

use App\Models\User;
use App\Notifications\SmartLinkSubscribersFull;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_link_blocks', function (Blueprint $table) {
            $table->timestamp('subscriber_limit_notified_at')->nullable();
        });

        // Conserva los avisos ya emitidos con la deduplicación anterior.
        DB::table('smart_link_blocks')->where('type', 'email')->whereExists(
            DB::table('notifications')->selectRaw('1')
                ->join('smart_links', 'smart_links.user_id', '=', 'notifications.notifiable_id')
                ->where('notifications.notifiable_type', User::class)
                ->where('notifications.type', SmartLinkSubscribersFull::class)
                ->whereColumn('smart_links.id', 'smart_link_blocks.smart_link_id')
                ->whereColumn('notifications.data->block_id', 'smart_link_blocks.id')
        )->update(['subscriber_limit_notified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('smart_link_blocks', fn (Blueprint $table) => $table->dropColumn('subscriber_limit_notified_at'));
    }
};
