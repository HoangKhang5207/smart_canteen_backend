<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spending_limit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_card_id')->constrained('student_cards')->cascadeOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('previous_limit');
            $table->unsignedInteger('new_limit');
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['student_card_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spending_limit_logs');
    }
};
