<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Costos por receta y punto de equilibrio (docs/PLAN_COSTOS_PUNTO_EQUILIBRIO.md):
 *  - ingredients.tracks_stock: los componentes de costo (porción de açaí, mix de frutas…) solo calculan el costo
 *    y no descuentan stock en cada venta.
 *  - sale_items.weight_grams: gramos de los productos por peso (Autoservicio), que el POS ya enviaba pero no se
 *    guardaban.
 *  - expense_categories.cost_behavior: fijo o variable, para separar los costos fijos del punto de equilibrio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->boolean('tracks_stock')->default(true)->after('type')
                  ->comment('false = componente de costo: no descuenta stock al vender');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('weight_grams', 10, 2)->nullable()->after('subtotal')
                  ->comment('Gramos vendidos de un producto por peso');
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('cost_behavior', 10)->default('fixed')->after('section')
                  ->comment('fixed | variable (punto de equilibrio)');
        });

        // Las compras de insumos (costo de ventas) varían con lo que se vende
        DB::table('expense_categories')->where('section', 'cost_of_sales')->update(['cost_behavior' => 'variable']);
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('tracks_stock');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('weight_grams');
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn('cost_behavior');
        });
    }
};
