<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega campos de distribución de efectivo al cierre de caja y total de egresos.
     * - total_expenses: suma calculada de egresos del turno (se guarda al cerrar)
     * - cash_withdrawn: monto físico que el dueño/administrador retira de la gaveta
     * - cash_left_for_next: fondo de cambio que queda en la gaveta para el siguiente turno
     * Invariante: cash_withdrawn + cash_left_for_next == actual_closing (±0.01)
     */
    public function up(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->decimal('total_expenses', 12, 2)
                  ->default(0)
                  ->after('difference')
                  ->comment('Suma total de egresos de caja registrados durante el turno');
            $table->decimal('cash_withdrawn', 12, 2)
                  ->nullable()
                  ->after('total_expenses')
                  ->comment('Monto físico retirado de la gaveta al cerrar (depósito/dueño)');
            $table->decimal('cash_left_for_next', 12, 2)
                  ->nullable()
                  ->after('cash_withdrawn')
                  ->comment('Fondo dejado en gaveta para apertura del siguiente turno');
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->dropColumn(['total_expenses', 'cash_withdrawn', 'cash_left_for_next']);
        });
    }
};
