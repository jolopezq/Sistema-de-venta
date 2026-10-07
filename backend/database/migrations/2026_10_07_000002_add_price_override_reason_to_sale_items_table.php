<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Motivo con que el cajero ajustó el precio de una línea de un pedido PedidosYa
     * (promo de la plataforma, precio desactualizado...). Vacío = precio del tarifario.
     */
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('price_override_reason', 255)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('price_override_reason');
        });
    }
};
