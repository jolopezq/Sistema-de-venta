<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de configuración del motor de fidelización (cashback).
     * Define la tasa de acumulación de puntos, valor de redención,
     * días de expiración y categorías excluidas del programa.
     * Solo debería tener un registro activo a la vez (singleton de config).
     */
    public function up(): void
    {
        Schema::create('loyalty_config', function (Blueprint $table) {
            $table->id();
            $table->decimal('accumulation_rate', 8, 2)
                  ->comment('Monto en Bs necesario para ganar 1 punto (ej: 10.00)');
            $table->decimal('redemption_value', 8, 2)
                  ->comment('Valor en Bs de cada punto al redimir');
            $table->integer('points_expiration_days')
                  ->comment('Días hasta que los puntos venzan');
            $table->json('excluded_categories')->nullable()
                  ->comment('IDs de categorías excluidas de acumulación');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_config');
    }
};
