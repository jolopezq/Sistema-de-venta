<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gasto de caja pagado en parte con efectivo y en parte por QR (docs/PLAN_GASTO_PAGO_DIVIDIDO.md).
 * Con payment_method = 'mixed', cash_amount es lo que salió de la gaveta; el resto (amount − cash_amount), por QR o banco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->decimal('cash_amount', 12, 2)->nullable()->after('payment_method')
                ->comment('Solo con payment_method = mixed: parte pagada con efectivo de la gaveta');
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropColumn('cash_amount');
        });
    }
};
