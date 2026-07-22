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
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('delivery_price', 10, 2)->nullable()->after('vip_price');
        });

        Schema::table('options', function (Blueprint $table) {
            $table->decimal('delivery_price', 10, 2)->nullable()->after('additional_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('delivery_price');
        });

        Schema::table('options', function (Blueprint $table) {
            $table->dropColumn('delivery_price');
        });
    }
};
