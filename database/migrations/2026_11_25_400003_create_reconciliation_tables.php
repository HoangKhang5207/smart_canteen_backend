<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_batches', function (Blueprint $table) {
            $table->id();
            $table->date('reconciliation_date');
            $table->string('provider', 32);
            $table->string('status', 24);
            $table->unsignedInteger('total_local_count')->default(0);
            $table->unsignedInteger('total_provider_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('discrepancy_count')->default(0);
            $table->unsignedBigInteger('total_local_amount')->default(0);
            $table->unsignedBigInteger('total_provider_amount')->default(0);
            $table->foreignId('run_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique(['reconciliation_date', 'provider']);
        });

        Schema::create('reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('reconciliation_batches')->cascadeOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->restrictOnDelete();
            $table->string('provider_reference', 120)->nullable();
            $table->string('order_code', 64)->nullable();
            $table->unsignedInteger('local_amount')->nullable();
            $table->unsignedInteger('provider_amount')->nullable();
            $table->string('status', 32);
            $table->string('note', 255)->nullable();
            $table->string('resolution_note', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_items');
        Schema::dropIfExists('reconciliation_batches');
    }
};
