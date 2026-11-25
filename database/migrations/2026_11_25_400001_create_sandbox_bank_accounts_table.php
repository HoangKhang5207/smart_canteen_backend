<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sandbox_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('provider_reference', 100)->unique();
            $table->string('bank_code', 20);
            $table->string('account_number_masked', 32);
            $table->unsignedBigInteger('balance')->default(500000);
            $table->string('simulated_mode', 24)->default('NORMAL');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sandbox_bank_accounts');
    }
};
