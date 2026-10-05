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
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->string('payment_method', 20)
                  ->default('cash')
                  ->after('type')
                  ->comment('Método de pago del gasto/ingreso: cash, qr, card. Solo cash afecta la gaveta física.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
