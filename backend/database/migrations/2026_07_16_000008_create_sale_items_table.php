<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de ítems individuales dentro de una venta.
     * Cada línea registra el producto, cantidad (unidades o gramos),
     * precio unitario y modificaciones de toppings para bowls.
     */
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 10, 4)->comment('Unidades o gramos');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('subtotal', 12, 2);
            $table->json('topping_modifications')->nullable()
                  ->comment('JSON con toppings base removidos y extras añadidos');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
