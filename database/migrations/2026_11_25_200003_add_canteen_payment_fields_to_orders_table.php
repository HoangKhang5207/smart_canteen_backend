<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('student_card_id')->nullable()->after('user_id')->constrained('student_cards')->restrictOnDelete();
            $table->string('payment_method', 24)->default('COUNTER_CASH')->after('status');
            $table->string('payment_status', 20)->default('UNPAID')->after('payment_method');
            $table->string('store_name', 150)->nullable()->after('payment_status');
            $table->unsignedInteger('discount_amount')->default(0)->after('total_amount');
            $table->unsignedInteger('remaining_limit_snapshot')->nullable()->after('discount_amount');
            $table->string('payment_reference', 100)->nullable()->after('remaining_limit_snapshot');

            $table->index(['student_card_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['student_card_id', 'created_at']);
            $table->dropConstrainedForeignId('student_card_id');
            $table->dropColumn([
                'payment_method',
                'payment_status',
                'store_name',
                'discount_amount',
                'remaining_limit_snapshot',
                'payment_reference',
            ]);
        });
    }
};
