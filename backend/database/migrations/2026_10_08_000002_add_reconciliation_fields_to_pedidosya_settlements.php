<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conciliación completa de la semana PedidosYa (auditoría de Finanzas, M3):
 * descuentos del CSV para comparar ventas brutas − descuento PedidosYa con las ventas netas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidosya_settlements', function (Blueprint $table) {
            $table->decimal('csv_pedidosya_discount', 12, 2)->default(0)->after('csv_estimated_income')
                  ->comment('Descuentos financiados por PedidosYa en pedidos entregados (CSV)');
            $table->decimal('csv_vendor_discount', 12, 2)->default(0)->after('csv_pedidosya_discount')
                  ->comment('Descuentos financiados por el local en pedidos entregados (CSV)');
        });

        // Semanas ya importadas: se calculan desde sus pedidos guardados
        foreach (DB::table('pedidosya_settlements')->pluck('id') as $id) {
            $delivered = DB::table('pedidosya_orders')
                ->where('settlement_id', $id)
                ->whereRaw('LOWER(status) = ?', ['entregado']);

            DB::table('pedidosya_settlements')->where('id', $id)->update([
                'csv_pedidosya_discount' => round((float) (clone $delivered)->sum('pedidosya_discount'), 2),
                'csv_vendor_discount'    => round((float) (clone $delivered)->sum('vendor_discount'), 2),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('pedidosya_settlements', function (Blueprint $table) {
            $table->dropColumn(['csv_pedidosya_discount', 'csv_vendor_discount']);
        });
    }
};
