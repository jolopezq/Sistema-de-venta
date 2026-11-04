<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planilla de sueldos (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.6). Aditiva.
 *
 * - payroll_periods: una planilla aprobada (o anulada) de un período.
 * - payroll_lines: una línea por persona, con las tarifas usadas (snapshot) y el detalle del cálculo.
 * - cash_movements.employee_id: un egreso de caja «Salarios y personal» puede ser el adelanto de una persona;
 *   la planilla lo resta y el gasto de Finanzas no lo cuenta dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 12)->default('approved')->comment('approved | voided');
            $table->decimal('gross_total', 12, 2)->default(0);
            $table->decimal('advances_total', 12, 2)->default(0);
            $table->decimal('others_total', 12, 2)->default(0);
            $table->decimal('net_total', 12, 2)->default(0);
            $table->string('payment_method', 30)->default('cash');
            $table->foreignId('finance_expense_id')->nullable()->constrained('finance_expenses')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['period_start', 'period_end', 'status']);
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('pay_mode', 20);
            $table->unsignedSmallInteger('days_worked')->default(0);
            $table->unsignedInteger('normal_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('absences')->default(0);
            $table->decimal('gross', 12, 2)->default(0);
            $table->decimal('advances', 12, 2)->default(0);
            $table->decimal('others', 12, 2)->default(0);
            $table->string('others_reason', 255)->nullable();
            $table->decimal('net', 12, 2)->default(0);
            $table->json('snapshot')->comment('Tarifas y reglas usadas, y el desglose del bruto');
            $table->timestamps();
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('expense_category_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
        });
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_periods');
    }
};
