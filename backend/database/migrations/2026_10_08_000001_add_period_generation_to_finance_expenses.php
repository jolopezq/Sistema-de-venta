<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gastos fijos por período (auditoría de Finanzas, A2).
 *
 * Un gasto fijo deja de sumarse "solo" en cada mes: cada mes se genera un gasto real
 * (`finance_expenses`) vinculado a su plantilla y período, único por plantilla y mes.
 * Así cambiar el monto de la plantilla no reescribe meses pasados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_expenses', function (Blueprint $table) {
            $table->string('start_month', 7)->nullable()->after('day_of_month')
                  ->comment('Primer mes en que corresponde (AAAA-MM)');
            $table->string('payment_method', 30)->default('bank_transfer')->after('start_month')
                  ->comment('Forma de pago habitual: cash, qr, card, bank_transfer');
        });

        // Las plantillas existentes empiezan en el mes en que se registraron
        foreach (DB::table('recurring_expenses')->select('id', 'created_at')->get() as $row) {
            $month = $row->created_at ? substr((string) $row->created_at, 0, 7) : now()->format('Y-m');
            DB::table('recurring_expenses')->where('id', $row->id)->update(['start_month' => $month]);
        }

        Schema::table('finance_expenses', function (Blueprint $table) {
            $table->foreignId('recurring_expense_id')->nullable()->after('user_id')
                  ->constrained('recurring_expenses')->nullOnDelete()
                  ->comment('Gasto fijo que lo generó');
            $table->string('period', 7)->nullable()->after('recurring_expense_id')
                  ->comment('Mes del gasto fijo generado (AAAA-MM)');
            $table->unique(['recurring_expense_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::table('finance_expenses', function (Blueprint $table) {
            $table->dropUnique(['recurring_expense_id', 'period']);
            $table->dropConstrainedForeignId('recurring_expense_id');
            $table->dropColumn('period');
        });

        Schema::table('recurring_expenses', function (Blueprint $table) {
            $table->dropColumn(['start_month', 'payment_method']);
        });
    }
};
