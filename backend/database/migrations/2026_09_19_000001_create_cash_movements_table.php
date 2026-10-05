<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de movimientos de efectivo durante un turno de caja.
     * Registra egresos (compra de insumos, pago de delivery, etc.) e ingresos extraordinarios.
     * El total de egresos se descuenta del efectivo esperado en el arqueo de cierre.
     * Cualquier cajero con turno abierto puede registrar movimientos.
     */
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_session_id')
                  ->nullable()
                  ->constrained('cash_register_sessions')
                  ->nullOnDelete()
                  ->comment('Sesión de caja a la que pertenece el movimiento');
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->restrictOnDelete()
                  ->comment('Usuario que registró el movimiento');
            $table->enum('type', ['expense', 'extra_income'])
                  ->default('expense')
                  ->comment('expense = egreso de caja, extra_income = ingreso extraordinario');
            $table->decimal('amount', 12, 2)
                  ->comment('Monto del movimiento en moneda local (Bs)');
            $table->text('description')
                  ->comment('Descripción libre del motivo (ej: Bs 50 compra frutilla mercado)');
            $table->foreignUuid('sale_id')
                  ->nullable()
                  ->constrained('sales')
                  ->nullOnDelete()
                  ->comment('Venta asociada opcional (ej: delivery cobrado en efectivo de venta QR)');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
