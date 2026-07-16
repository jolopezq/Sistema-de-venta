<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de pedidos de delivery recibidos desde plataformas externas.
     * Almacena el payload crudo del webhook y rastrea el estado
     * del pedido hasta que el repartidor lo retira.
     */
    public function up(): void
    {
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->string('platform')->default('pedidosya');
            $table->string('external_id')->unique()->comment('ID asignado por la plataforma externa');
            $table->json('order_payload')->comment('Datos crudos recibidos del webhook');
            $table->enum('status', ['received', 'preparing', 'ready', 'picked_up'])->default('received');
            $table->foreignUuid('sale_id')->nullable()->constrained('sales')->nullOnDelete()
                  ->comment('Venta interna generada a partir de este pedido');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_orders');
    }
};
