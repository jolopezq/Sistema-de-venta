<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conciliación de caja (docs/PLAN_CONCILIACION_CAJA.md). Aditiva: dos columnas nulas.
 *  - opening_note: motivo cuando el fondo con el que se abre no coincide con lo que dejó la caja anterior (E).
 *  - qr_bank_reported: cobros QR del día que muestra la app del banco, anotados al cerrar (G).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->string('opening_note', 255)->nullable()->after('opening_amount');
            $table->decimal('qr_bank_reported', 10, 2)->nullable()->after('cash_left_for_next');
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->dropColumn(['opening_note', 'qr_bank_reported']);
        });
    }
};
