<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de productos finales que se venden al cliente.
     * Los productos no tienen stock propio; se componen de insumos vía recetas.
     * Soporte para venta por peso (gramos) y precios preferenciales VIP.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('image_url')->nullable()->comment('Foto/icono para la cuadrícula del POS');
            $table->decimal('price', 10, 2);
            $table->decimal('vip_price', 10, 2)->nullable()->comment('Precio preferencial para clientes VIP');
            $table->boolean('is_weight_based')->default(false)->comment('true = venta por peso en gramos');
            $table->decimal('price_per_gram', 8, 4)->nullable();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->enum('printer_target', ['kitchen', 'bar', 'none'])->default('kitchen')
                  ->comment('Área a donde se dirige la comanda de este producto');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
