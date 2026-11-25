<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spending_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_card_id')->unique()->constrained('student_cards')->cascadeOnDelete();
            $table->unsignedInteger('daily_limit')->default(0);
            $table->unsignedInteger('spent_today')->default(0);
            $table->date('spent_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spending_limits');
    }
};
