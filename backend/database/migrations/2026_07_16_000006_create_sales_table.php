<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla principal de ventas / tickets.
     * Usa UUID como PK generado en el frontend (Offline-First).
     * El campo sync_status permite rastrear tickets pendientes de sincronización.
     * El campo source diferencia ventas de caja directa vs PedidosYa.
     */
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('UUIDv4 generado en frontend');
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0)
                  ->comment('Descuento aplicado por puntos o precio VIP');
            $table->decimal('total_amount', 12, 2);
            $table->enum('status', ['completed', 'voided'])->default('completed');
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete()
                  ->comment('Admin que autorizó la anulación con PIN');
            $table->enum('source', ['pos', 'pedidosya'])->default('pos');
            $table->enum('sync_status', ['pending', 'synced', 'failed'])->default('synced')
                  ->comment('Estado de sincronización offline → server');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
