<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca qué productos se venden por PedidosYa. En modo PedidosYa el POS solo muestra estos.
     *
     * Los productos por peso (hoy solo "Autoservicio") quedan fuera: no tienen precio
     * PedidosYa por gramo.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('available_on_pedidosya')->default(true)->after('delivery_price');
        });

        DB::table('products')->where('is_weight_based', true)->update(['available_on_pedidosya' => false]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('available_on_pedidosya');
        });
    }
};
