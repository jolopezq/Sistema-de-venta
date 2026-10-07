<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('section', 30)->comment('cost_of_sales, operating, financial, taxes');
            $table->string('group_name', 100)->nullable()->comment('Agrupador contable o visual');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Categorías iniciales estándar según §9.1 del Plan de Finanzas
        $categories = [
            // Costo de ventas
            [
                'name' => 'Helado y açaí',
                'section' => 'cost_of_sales',
                'group_name' => 'Insumos y alimentos',
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Ingredientes y frutas',
                'section' => 'cost_of_sales',
                'group_name' => 'Insumos y alimentos',
                'sort_order' => 2,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Descartables (vasos, cucharas)',
                'section' => 'cost_of_sales',
                'group_name' => 'Insumos y alimentos',
                'sort_order' => 3,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Empaques delivery',
                'section' => 'cost_of_sales',
                'group_name' => 'Costos de entrega',
                'sort_order' => 4,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Gastos operativos
            [
                'name' => 'Salarios y personal',
                'section' => 'operating',
                'group_name' => 'Personal',
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Alquiler local',
                'section' => 'operating',
                'group_name' => 'Infraestructura',
                'sort_order' => 11,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Servicios básicos (luz, agua)',
                'section' => 'operating',
                'group_name' => 'Servicios',
                'sort_order' => 12,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Internet y teléfono',
                'section' => 'operating',
                'group_name' => 'Servicios',
                'sort_order' => 13,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Publicidad propia y marketing',
                'section' => 'operating',
                'group_name' => 'Marketing',
                'sort_order' => 14,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Mantenimiento y reparaciones',
                'section' => 'operating',
                'group_name' => 'Operaciones',
                'sort_order' => 15,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Limpieza e insumos operativos',
                'section' => 'operating',
                'group_name' => 'Operaciones',
                'sort_order' => 16,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Otros gastos operativos',
                'section' => 'operating',
                'group_name' => 'Otros',
                'sort_order' => 17,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Gastos financieros
            [
                'name' => 'Intereses y comisiones bancarias',
                'section' => 'financial',
                'group_name' => 'Financiero',
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // Impuestos
            [
                'name' => 'Régimen simplificado',
                'section' => 'taxes',
                'group_name' => 'Impuestos',
                'sort_order' => 30,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('expense_categories')->insert($categories);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
