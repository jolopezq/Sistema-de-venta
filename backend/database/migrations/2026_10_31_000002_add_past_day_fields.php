<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ventas de días pasados (docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md, Fase 3):
 * - app_settings: ajustes simples del sistema (fecha de inicio del sistema);
 * - sales.entry_mode: «historical» para ventas anteriores al inicio del sistema (sin stock ni puntos);
 * - sales.paper_ref: N.º de la comanda en papel, para no cargarla dos veces;
 * - cash_movements.occurred_at: cuándo ocurrió el egreso (Finanzas y el arqueo usan esta fecha, no la de carga).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('entry_mode', 12)->default('normal')->after('source')
                ->comment('normal o historical (anterior al inicio del sistema: no descuenta stock ni suma puntos)');
            $table->string('paper_ref', 30)->nullable()->after('entry_mode')->comment('N.º de comanda en papel');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dateTime('occurred_at')->nullable()->after('description');
            $table->index('occurred_at');
        });
        DB::table('cash_movements')->whereNull('occurred_at')->update(['occurred_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropIndex(['occurred_at']);
            $table->dropColumn('occurred_at');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['entry_mode', 'paper_ref']);
        });
        Schema::dropIfExists('app_settings');
    }
};
