<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `type`, `waste_category` y `reference_type` eran enum (en SQLite, una restricción CHECK que obliga a
     * reconstruir la tabla para agregar un valor). Pasan a texto corto; los valores válidos los controlan
     * las validaciones de la aplicación. Permite el movimiento «initial» (stock inicial) y los motivos de
     * merma y referencias de compra de las fases siguientes del plan de inventario.
     * No cambia ningún dato: solo la definición de las columnas.
     */
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('type', 30)->change();
            $table->string('waste_category', 30)->nullable()->change();
            $table->string('reference_type', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        // No se vuelve a enum: los movimientos nuevos pueden usar valores que el enum rechazaría.
    }
};
