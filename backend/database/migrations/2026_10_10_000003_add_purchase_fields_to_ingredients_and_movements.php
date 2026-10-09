<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos de compra de cada insumo y costo de cada movimiento (plan de inventario, F1).
 * Solo agrega columnas opcionales: no modifica datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('ingredient_category_id')
                  ->constrained('suppliers')->nullOnDelete()
                  ->comment('Proveedor habitual');
            $table->string('purchase_unit', 30)->nullable()->comment('Cómo se compra: bolsa, caja, saco…');
            $table->decimal('purchase_factor', 12, 4)->default(1)->comment('Unidades de consumo por unidad de compra');
            $table->decimal('lot_size', 12, 4)->nullable()->comment('Pedido mínimo, en unidades de compra');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 4)->nullable()
                  ->comment('Compra o stock inicial: costo de esa entrada. Resto de movimientos: CPP del momento');
            $table->decimal('total_cost', 12, 2)->nullable()->comment('|cantidad| × unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'total_cost']);
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['purchase_unit', 'purchase_factor', 'lot_size']);
        });
    }
};
