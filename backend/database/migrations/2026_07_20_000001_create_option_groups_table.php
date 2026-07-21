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
        Schema::create('option_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Ej. Tamaño, Frutas, Toppings');
            $table->integer('min_selections')->default(0)->comment('Mínimo de opciones a seleccionar');
            $table->integer('max_selections')->nullable()->comment('Máximo de opciones a seleccionar (null = sin límite)');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('option_groups');
    }
};
