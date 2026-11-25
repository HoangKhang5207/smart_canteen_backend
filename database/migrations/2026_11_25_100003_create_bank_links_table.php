<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_card_id')->constrained('student_cards')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('bank_code', 20);
            $table->string('bank_name', 100);
            $table->text('account_number_encrypted');
            $table->string('account_number_masked', 32);
            $table->string('account_holder_name', 150);
            $table->text('national_id_encrypted');
            $table->string('national_id_masked', 32);
            $table->string('phone_number', 20);
            $table->string('phone_masked', 20);
            $table->string('email', 150)->nullable();
            $table->boolean('auto_debit_agreed')->default(true);
            $table->timestamp('auto_debit_agreed_at');
            $table->string('provider_reference', 100)->nullable();
            $table->string('status', 24)->default('PENDING_OTP');
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('unlinked_at')->nullable();
            $table->timestamps();

            $table->index(['student_card_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX bank_links_one_active_per_card_idx ON bank_links (student_card_id) WHERE status = 'ACTIVE'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bank_links_one_active_per_card_idx');
        Schema::dropIfExists('bank_links');
    }
};
