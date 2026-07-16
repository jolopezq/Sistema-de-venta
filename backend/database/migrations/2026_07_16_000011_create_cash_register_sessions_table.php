<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de sesiones de caja (arqueos).
     * Cada turno de cajero abre una sesión con fondo inicial.
     * Al cerrar, el sistema calcula el esperado vs el declarado.
     */
    public function up(): void
    {
        Schema::create('cash_register_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->decimal('opening_amount', 12, 2)->comment('Fondo de caja inicial');
            $table->decimal('expected_closing', 12, 2)->nullable()
                  ->comment('Monto esperado calculado por el sistema');
            $table->decimal('actual_closing', 12, 2)->nullable()
                  ->comment('Monto real declarado por el cajero');
            $table->decimal('difference', 12, 2)->nullable()
                  ->comment('Sobrante (+) o faltante (-)');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_sessions');
    }
};
