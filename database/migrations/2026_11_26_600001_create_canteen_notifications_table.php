<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canteen_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('severity', 16)->default('INFO');
            $table->string('title', 160);
            $table->string('body', 500);
            $table->string('action_url', 160)->nullable();
            $table->jsonb('meta')->nullable();
            $table->string('push_channel', 24)->default('log');
            $table->string('push_status', 24)->default('SKIPPED');
            $table->string('push_reference', 120)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at', 'id'], 'canteen_notifications_user_read_idx');
            $table->index(['user_id', 'type', 'created_at'], 'canteen_notifications_user_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canteen_notifications');
    }
};
