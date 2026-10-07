<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Tabla de liquidaciones semanales de PedidosYa
        Schema::create('pedidosya_settlements', function (Blueprint $table) {
            $table->id();
            $table->date('start_date')->comment('Fecha inicio de la semana');
            $table->date('end_date')->comment('Fecha fin de la semana');
            $table->date('payout_date')->nullable()->comment('Fecha de transferencia/pago en banco');

            // 8 montos oficiales del estado de cuenta PDF (§6.1)
            $table->decimal('net_sales', 12, 2)->comment('1. Ventas netas');
            $table->decimal('delivery_fees', 12, 2)->default(0)->comment('2. Envíos a cargo del local');
            $table->decimal('service_fees', 12, 2)->comment('3. Servicios PedidosYa (comisiones)');
            $table->decimal('operating_charges', 12, 2)->default(0)->comment('4. Cargos operativos');
            $table->decimal('advertising', 12, 2)->default(0)->comment('5. Publicidad en la app');
            $table->decimal('refunds', 12, 2)->default(0)->comment('6. Reintegros recibidos');
            $table->decimal('taxes', 12, 2)->default(0)->comment('7. Impuestos / IVA comisiones');
            $table->decimal('total_payout', 12, 2)->comment('8. Total liquidado (pago final)');

            // Conciliación y métricas calculadas
            $table->decimal('calculated_payout', 12, 2)->comment('Monto liquidado según fórmula');
            $table->decimal('discrepancy', 12, 2)->default(0)->comment('Diferencia entre total declarado y calculado');
            $table->integer('orders_count')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('cancelled_count')->default(0);
            $table->decimal('csv_gross_sales', 12, 2)->default(0)->comment('Ventas brutas calculadas desde el CSV');
            $table->decimal('csv_commission', 12, 2)->default(0)->comment('Comisión total calculada desde el CSV');
            $table->decimal('csv_estimated_income', 12, 2)->default(0)->comment('Ingreso estimado total del CSV');

            $table->string('zip_path', 255)->nullable()->comment('Ruta relativa del archivo ZIP respaldado');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['start_date', 'end_date']);
        });

        // 2. Tabla de detalle de pedidos importados desde el CSV
        Schema::create('pedidosya_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('pedidosya_settlements')->cascadeOnDelete();
            $table->string('order_number', 50)->unique()->comment('Nro de pedido único de PedidosYa');
            $table->string('status', 40)->comment('Entregado, Cancelado, Retirado...');
            $table->dateTime('order_date')->comment('Fecha y hora del pedido en La Paz');
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('ready_at')->nullable();
            $table->dateTime('pickup_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('payment_method', 50)->nullable()->comment('Pago online, Efectivo, etc.');
            $table->string('delivery_type', 50)->nullable()->comment('Delivery de PedidosYa, Retirado, etc.');
            $table->decimal('subtotal', 12, 2)->default(0)->comment('Total parcial');
            $table->decimal('commission', 12, 2)->default(0)->comment('Comisión PedidosYa');
            $table->decimal('tax', 12, 2)->default(0)->comment('Impuesto');
            $table->decimal('vendor_discount', 12, 2)->default(0)->comment('Descuento financiado por local');
            $table->decimal('pedidosya_discount', 12, 2)->default(0)->comment('Descuento financiado por PedidosYa');
            $table->decimal('estimated_income', 12, 2)->default(0)->comment('Ingreso estimado');
            $table->text('items_text')->nullable()->comment('Texto de artículos del pedido');
            $table->text('cancellation_reason')->nullable();
            $table->foreignUuid('sale_id')->nullable()->constrained('sales')->nullOnDelete()->comment('Venta vinculada en POS');
            $table->json('raw_data')->nullable()->comment('Fila original completa del CSV');
            $table->timestamps();

            $table->index(['settlement_id', 'status']);
            $table->index('order_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidosya_orders');
        Schema::dropIfExists('pedidosya_settlements');
    }
};
