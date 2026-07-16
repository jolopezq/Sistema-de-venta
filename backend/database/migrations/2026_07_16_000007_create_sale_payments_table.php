<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de pagos asociados a una venta.
     * Separada de sales para soportar pagos mixtos
     * (ej: parte efectivo + parte QR en una misma venta).
     */
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->enum('method', ['cash', 'card', 'qr', 'delivery_platform', 'mixed']);
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
