<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corregir cajas cerradas (docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md, Fase 4): al reabrir una caja se guarda su cierre
 * anterior tal cual, con quién la reabrió y por qué; al volver a cerrarla, quién y con qué motivo.
 * cash_register_sessions.reopened_at marca la caja abierta para corregir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_session_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_session_id')->constrained('cash_register_sessions')->cascadeOnDelete();
            $table->decimal('expected_closing', 12, 2)->nullable();
            $table->decimal('actual_closing', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable();
            $table->decimal('total_expenses', 12, 2)->nullable();
            $table->decimal('cash_withdrawn', 12, 2)->nullable();
            $table->decimal('cash_left_for_next', 12, 2)->nullable();
            $table->json('bill_breakdown')->nullable();
            $table->text('diff_note')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reopened_at');
            $table->text('reopen_reason');
            $table->foreignId('reclosed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reclosed_at')->nullable();
            $table->text('reclose_reason')->nullable();
            $table->timestamps();
        });

        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->dateTime('reopened_at')->nullable()->after('closed_at')
                ->comment('Caja cerrada reabierta para corregir; vuelve a null al cerrarla');
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->dropColumn('reopened_at');
        });
        Schema::dropIfExists('cash_session_closings');
    }
};
