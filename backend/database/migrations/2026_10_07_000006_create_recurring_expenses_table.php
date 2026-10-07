<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->comment('Concepto del gasto fijo (ej: Alquiler, Salario Juan)');
            $table->foreignId('expense_category_id')
                  ->constrained('expense_categories')
                  ->restrictOnDelete()
                  ->comment('Categoría contable asignada');
            $table->decimal('amount', 12, 2)->comment('Monto habitual en Bs');
            $table->string('frequency', 30)->default('monthly')->comment('monthly, bimonthly, quarterly, annual');
            $table->unsignedTinyInteger('day_of_month')->default(1)->comment('Día del mes estimado de pago (1-31)');
            $table->boolean('is_active')->default(true)->comment('Si está activo para considerarse en cálculos');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_expenses');
    }
};
