<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composición de los componentes de costeo (plan de inventario, F2).
 *
 * Un componente («Porción base açaí», «Kit descartable chico») no lleva stock: es una porción que usan las recetas.
 * Su composición dice qué insumos reales consume una porción: «Porción base açaí» = 0,18 kg de pulpa. Al vender,
 * el sistema descuenta esos insumos; el costo del componente sale de su costo promedio. Un componente sin
 * composición sigue como hoy (costo fijo, sin stock). Solo crea una tabla: no modifica datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_compositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained('ingredients')->cascadeOnDelete()
                  ->comment('Componente de costeo (tracks_stock = false)');
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete()
                  ->comment('Insumo real que consume (tracks_stock = true)');
            $table->decimal('quantity', 12, 4)->comment('Cantidad del insumo por 1 unidad del componente, en la unidad del insumo');
            $table->timestamps();

            $table->unique(['component_id', 'ingredient_id']);
            $table->index('ingredient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_compositions');
    }
};
