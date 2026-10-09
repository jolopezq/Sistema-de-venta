<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proveedores y líneas de compra (plan de inventario, F1).
 *
 * Una compra es un gasto de Finanzas (`finance_expenses`) con líneas (`finance_expense_items`): cada línea de
 * insumo sube el stock y actualiza el costo promedio; una línea sin insumo (gas, limpieza) es solo gasto.
 * Las compras ya cargadas siguen como gastos sin líneas. Solo crea tablas y columnas: no modifica datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('name_key', 150)->unique()->comment('Nombre sin mayúsculas ni acentos, para no duplicar');
            $table->string('phone', 30)->nullable();
            $table->unsignedSmallInteger('delivery_days')->default(1)->comment('Días que tarda en llegar un pedido');
            $table->string('order_days', 100)->nullable()->comment('Días en que se hace el pedido (texto libre)');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('finance_expenses', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('supplier')
                  ->constrained('suppliers')->nullOnDelete()
                  ->comment('Proveedor registrado (el texto `supplier` se conserva para los gastos antiguos)');
        });

        Schema::create('finance_expense_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_expense_id')->constrained('finance_expenses')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained('ingredients')->nullOnDelete();
            $table->string('description', 150)->nullable()->comment('Concepto de una línea sin insumo (gas, limpieza)');
            $table->decimal('quantity', 12, 4)->comment('Cantidad en la unidad de compra (bolsas, cajas…)');
            $table->string('purchase_unit', 30)->nullable();
            $table->decimal('factor', 12, 4)->default(1)->comment('Unidades de consumo por unidad de compra');
            $table->decimal('stock_quantity', 12, 4)->comment('quantity × factor, en la unidad del insumo');
            $table->decimal('unit_cost', 12, 4)->comment('Costo por unidad de consumo (total ÷ stock_quantity)');
            $table->decimal('total', 12, 2)->comment('Importe de la línea en Bs');
            $table->timestamps();

            $table->index('ingredient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_expense_items');

        Schema::table('finance_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });

        Schema::dropIfExists('suppliers');
    }
};
