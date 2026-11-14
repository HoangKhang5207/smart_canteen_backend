<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('code', 32)->unique();
            $table->string('status', 20)->default('PENDING');
            $table->unsignedInteger('total_amount');
            $table->string('note', 500)->nullable();
            $table->string('idempotency_key', 100);
            $table->string('payload_hash', 64);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
