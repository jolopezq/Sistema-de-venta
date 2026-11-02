<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descuento manual en caja y en Control diario (docs/PLAN_DESCUENTOS_CAJA.md): motivo obligatorio del descuento.
 * Va en su propia columna y no en `notes`, que se imprime en la comanda de cocina.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('discount_reason', 255)->nullable()->after('discount_amount')
                ->comment('Motivo del descuento manual');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('discount_reason');
        });
    }
};
