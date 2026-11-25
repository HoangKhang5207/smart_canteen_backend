<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_verifications', function (Blueprint $table) {
            $table->string('delivery_provider', 32)->nullable()->after('phone_masked');
            $table->string('delivery_status', 24)->nullable()->after('delivery_provider');
            $table->string('delivery_reference', 120)->nullable()->after('delivery_status');
        });
    }

    public function down(): void
    {
        Schema::table('otp_verifications', function (Blueprint $table) {
            $table->dropColumn(['delivery_provider', 'delivery_status', 'delivery_reference']);
        });
    }
};
