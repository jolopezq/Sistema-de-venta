<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos para el punto de reorden (plan de inventario, F4, §2.1). Solo agrega columnas opcionales: no modifica
 * datos existentes. El punto de reorden manual es el `minimum_stock` que ya existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->string('reorder_policy', 10)->default('reorder')->comment('reorder = punto de reorden, daily = compra diaria, none = sin control');
            $table->unsignedSmallInteger('target_days')->nullable()->comment('Días de cobertura objetivo (T) al pedir');
            $table->decimal('safety_days', 5, 2)->nullable()->comment('Días de seguridad mientras hay pocos datos');
            $table->unsignedSmallInteger('shelf_life_days')->nullable()->comment('Vida útil en días: limita cuánto comprar');
            $table->decimal('daily_estimate', 12, 4)->nullable()->comment('Consumo diario estimado por el dueño, en la unidad del insumo');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn(['reorder_policy', 'target_days', 'safety_days', 'shelf_life_days', 'daily_estimate']);
        });
    }
};
