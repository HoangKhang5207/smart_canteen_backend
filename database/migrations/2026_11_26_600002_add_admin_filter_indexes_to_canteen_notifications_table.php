<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('canteen_notifications', function (Blueprint $table) {
            $table->index(['push_status', 'id'], 'canteen_notifications_push_status_id_idx');
            $table->index(['type', 'id'], 'canteen_notifications_type_id_idx');
            $table->index(['read_at'], 'canteen_notifications_read_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('canteen_notifications', function (Blueprint $table) {
            $table->dropIndex('canteen_notifications_push_status_id_idx');
            $table->dropIndex('canteen_notifications_type_id_idx');
            $table->dropIndex('canteen_notifications_read_at_idx');
        });
    }
};
