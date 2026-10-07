<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La importación semanal crea o vincula ventas (plan de Finanzas §6.3, auditoría A4):
 * se guarda cómo se vinculó cada pedido y su total según el Tarifario para el informe semanal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidosya_orders', function (Blueprint $table) {
            $table->string('sale_link', 20)->nullable()->after('sale_id')
                  ->comment('created: venta creada por la importación; linked: venta del POS vinculada');
            $table->decimal('catalog_total', 12, 2)->nullable()->after('sale_link')
                  ->comment('Total del pedido según el Tarifario PedidosYa al importarlo');
        });
    }

    public function down(): void
    {
        Schema::table('pedidosya_orders', function (Blueprint $table) {
            $table->dropColumn(['sale_link', 'catalog_total']);
        });
    }
};
