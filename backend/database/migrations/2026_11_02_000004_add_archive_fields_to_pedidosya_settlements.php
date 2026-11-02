<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidosya_settlements', function (Blueprint $table) {
            $table->string('statement_number', 50)->nullable()->unique()->after('id')->comment('Número oficial de liquidación / estado de cuenta');
            $table->string('orders_source', 10)->nullable()->after('cancelled_count')->comment('Origen de los pedidos: csv o xls');
            $table->string('invoice_number', 50)->nullable()->after('zip_path')->comment('Número de factura emitida por PedidosYa');
            $table->date('invoice_date')->nullable()->after('invoice_number')->comment('Fecha de emisión de la factura fiscal');
            $table->string('invoice_authorization', 255)->nullable()->after('invoice_date')->comment('Código de autorización fiscal de la factura');
            $table->decimal('invoice_total', 12, 2)->nullable()->after('invoice_authorization')->comment('Total facturado por PedidosYa');
            $table->json('claims')->nullable()->after('invoice_total')->comment('Detalle de reclamos y cargos operativos desglosados');
            $table->json('files')->nullable()->after('claims')->comment('Rutas a los archivos archivados (statement, orders, invoice)');
        });
    }

    public function down(): void
    {
        Schema::table('pedidosya_settlements', function (Blueprint $table) {
            $table->dropColumn([
                'statement_number',
                'orders_source',
                'invoice_number',
                'invoice_date',
                'invoice_authorization',
                'invoice_total',
                'claims',
                'files',
            ]);
        });
    }
};
