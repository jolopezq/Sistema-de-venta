<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla pivote de recetas: vincula productos finales con sus insumos.
     * Define cuánta cantidad de cada insumo se requiere para producir
     * una unidad del producto. Clave para el descuento automático de stock.
     */
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->decimal('quantity_required', 10, 4)
                  ->comment('Cantidad de insumo necesaria por unidad de producto');
            $table->timestamps();

            // Un producto no puede tener el mismo insumo listado dos veces
            $table->unique(['product_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
