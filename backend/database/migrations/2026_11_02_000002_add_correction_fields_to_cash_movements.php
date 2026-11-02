<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos para corregir y anular gastos e ingresos de caja (docs/PLAN_CORREGIR_GASTOS_CAJA.md).
 * Permite trazabilidad completa: qué cambió, quién, cuándo y por qué motivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('edited_by')->nullable()->after('expense_category_id')->constrained('users')->nullOnDelete();
            $table->dateTime('edited_at')->nullable()->after('edited_by');
            $table->string('edit_reason', 255)->nullable()->after('edited_at');
            $table->json('edit_previous')->nullable()->after('edit_reason');
            $table->unsignedSmallInteger('edit_count')->default(0)->after('edit_previous');

            $table->foreignId('voided_by')->nullable()->after('edit_count')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edited_by');
            $table->dropColumn(['edited_at', 'edit_reason', 'edit_previous', 'edit_count']);
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn('void_reason');
        });
    }
};
