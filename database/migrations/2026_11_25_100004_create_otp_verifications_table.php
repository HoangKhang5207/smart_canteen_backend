<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('bank_link_id')->constrained('bank_links')->cascadeOnDelete();
            $table->string('purpose', 24);
            $table->string('phone_masked', 20);
            $table->string('code_hash', 255);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->unsignedSmallInteger('resend_count')->default(0);
            $table->unsignedSmallInteger('max_resends')->default(3);
            $table->timestamp('last_sent_at');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();

            $table->index(['bank_link_id', 'purpose', 'invalidated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_verifications');
    }
};
