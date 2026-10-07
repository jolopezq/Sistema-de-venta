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
        Schema::create('finance_expenses', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('Fecha del gasto/compra');
            $table->foreignId('expense_category_id')
                  ->constrained('expense_categories')
                  ->restrictOnDelete()
                  ->comment('Categoría contable');
            $table->string('supplier', 150)->nullable()->comment('Proveedor o beneficiario');
            $table->decimal('amount', 12, 2)->comment('Importe en Bs');
            $table->string('payment_method', 30)->default('cash')->comment('cash, qr, card, bank_transfer');
            $table->string('receipt_number', 100)->nullable()->comment('Número de recibo o factura');
            $table->text('notes')->nullable()->comment('Observaciones');
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->restrictOnDelete()
                  ->comment('Usuario que registró el gasto');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['date', 'expense_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_expenses');
    }
};
