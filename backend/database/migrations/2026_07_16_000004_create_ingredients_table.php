<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de insumos / materia prima.
     * Cada insumo tiene su stock actual, stock mínimo para alertas,
     * costo unitario y Costo Promedio Ponderado (CPP) precalculado.
     * Soporta múltiples unidades de medida y fechas de caducidad.
     */
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('unit', ['kg', 'litros', 'unidades', 'sacos']);
            $table->decimal('current_stock', 12, 4)->default(0);
            $table->decimal('minimum_stock', 12, 4)->default(0)->comment('Umbral para alertas visuales');
            $table->decimal('unit_cost', 10, 4)->default(0)->comment('Último costo unitario conocido');
            $table->decimal('weighted_avg_cost', 10, 4)->default(0)->comment('CPP precalculado');
            $table->date('expiration_date')->nullable()->comment('Fecha de vencimiento rígida');
            $table->date('min_shelf_date')->nullable()->comment('Caducidad mínima recomendada');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
