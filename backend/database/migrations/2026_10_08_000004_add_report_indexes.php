<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para los reportes por mes (Estado de Resultados, arqueo y Control Diario), auditoría B8:
 * las consultas filtran por estado o canal y un rango de `created_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'sales_status_created_at_index');
            $table->index(['source', 'created_at'], 'sales_source_created_at_index');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->index(['type', 'created_at'], 'cash_movements_type_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_status_created_at_index');
            $table->dropIndex('sales_source_created_at_index');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropIndex('cash_movements_type_created_at_index');
        });
    }
};
