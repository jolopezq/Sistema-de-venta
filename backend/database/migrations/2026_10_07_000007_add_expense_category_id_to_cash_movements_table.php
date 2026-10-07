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
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('expense_category_id')
                  ->nullable()
                  ->after('user_id')
                  ->constrained('expense_categories')
                  ->nullOnDelete()
                  ->comment('Categoría contable para egresos de caja (clasificación en Estado de Resultados)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_category_id');
        });
    }
};
