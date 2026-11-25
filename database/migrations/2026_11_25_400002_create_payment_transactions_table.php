<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('student_card_id')->nullable()->constrained('student_cards')->restrictOnDelete();
            $table->foreignId('bank_link_id')->nullable()->constrained('bank_links')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('type', 16);
            $table->unsignedInteger('amount');
            $table->string('status', 24);
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->string('provider_reference', 120)->nullable();
            $table->string('gateway_txn_ref', 120)->nullable()->unique();
            $table->jsonb('response_meta')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'created_at']);
            $table->index(['order_id', 'type']);
            $table->index(['student_card_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
