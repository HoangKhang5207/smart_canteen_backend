<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('stock_quantity')->nullable()->after('is_available');
            $table->unsignedInteger('low_stock_threshold')->default(5)->after('stock_quantity');

            $table->index(['is_available', 'stock_quantity'], 'products_available_stock_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_available_stock_idx');
            $table->dropColumn(['stock_quantity', 'low_stock_threshold']);
        });
    }
};
