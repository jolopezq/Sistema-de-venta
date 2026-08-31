<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->boolean('is_takeaway')->default(false)->after('source');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->boolean('is_takeaway')->default(false)->after('subtotal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('is_takeaway');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('is_takeaway');
        });
    }
};
