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
            $table->string('order_number', 30)->nullable()->index()->after('id')
                  ->comment('Número único de comanda legible (ej: 270826-0001)');
            $table->unsignedInteger('daily_sequence')->nullable()->after('order_number')
                  ->comment('Secuencia numérica del día (ej: 1, 2, 3...)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['order_number', 'daily_sequence']);
        });
    }
};
