<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de movimientos de inventario.
     * Registra toda entrada y salida de insumos: ventas automáticas,
     * mermas clasificadas, reabastecimiento y ajustes manuales.
     */
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->decimal('quantity_changed', 12, 4)
                  ->comment('Positivo = entrada, Negativo = salida');
            $table->enum('type', ['sale', 'waste', 'restock', 'adjustment']);
            $table->enum('waste_category', ['expired', 'damaged', 'spillage'])->nullable()
                  ->comment('Solo aplica cuando type = waste');
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
