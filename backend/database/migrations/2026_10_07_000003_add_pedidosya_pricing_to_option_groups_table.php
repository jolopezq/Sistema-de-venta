<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca qué grupos de opciones cobran precio propio en PedidosYa (por ejemplo, tamaños).
     * Los demás grupos (toppings, frutas) cobran siempre el mismo precio en ambos canales.
     */
    public function up(): void
    {
        Schema::table('option_groups', function (Blueprint $table) {
            $table->boolean('pedidosya_pricing')->default(false)->after('max_selections');
        });

        DB::table('option_groups')
            ->where(function ($query) {
                $query->where('name', 'LIKE', 'Tamaño%')
                      ->orWhere('name', 'LIKE', 'Tamano%')
                      ->orWhere('name', 'LIKE', 'tamaño%')
                      ->orWhere('name', 'LIKE', 'tamano%');
            })
            ->update(['pedidosya_pricing' => true]);
    }

    public function down(): void
    {
        Schema::table('option_groups', function (Blueprint $table) {
            $table->dropColumn('pedidosya_pricing');
        });
    }
};
