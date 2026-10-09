<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conteo físico de inventario (plan de inventario, F3). Solo agrega tablas y una columna opcional:
 * no modifica datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 10)->default('partial')->comment('key = insumos clave, all = todos, partial = una selección');
            $table->text('notes')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->useCurrent();
            $table->unsignedInteger('lines_count')->default(0);
            $table->decimal('difference_cost', 12, 2)->default(0)->comment('Suma firmada de las diferencias en Bs (negativo = faltante)');
            $table->timestamps();

            $table->index('counted_at');
        });

        Schema::create('inventory_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained('inventory_counts')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients');
            $table->decimal('expected', 12, 4)->comment('Stock del sistema al confirmar');
            $table->decimal('counted', 12, 4)->comment('Lo que se contó');
            $table->decimal('difference', 12, 4)->comment('contado − esperado');
            $table->decimal('unit_cost', 12, 4)->nullable()->comment('CPP del momento; null si el insumo no tiene costo');
            $table->decimal('cost', 12, 2)->nullable()->comment('diferencia × costo: negativo = faltante');
            $table->boolean('is_baseline')->default(false)->comment('Primer conteo del insumo: ordena el stock de partida y no cuenta como merma oculta');
            $table->timestamps();

            $table->unique(['inventory_count_id', 'ingredient_id']);
            $table->index('ingredient_id');
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->boolean('is_key')->default(false)->after('tracks_stock')->comment('Insumo clave: entra en el conteo semanal');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('is_key');
        });
        Schema::dropIfExists('inventory_count_lines');
        Schema::dropIfExists('inventory_counts');
    }
};
