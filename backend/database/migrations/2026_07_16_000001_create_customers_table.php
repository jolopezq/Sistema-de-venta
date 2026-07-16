<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de clientes del negocio.
     * Identificación por CI o número de celular (fricción cero en caja).
     * Incluye campos de fidelización (cashback) y segmentación dinámica.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('ci_or_phone')->unique()->comment('Carnet de Identidad o número de celular');
            $table->string('name');
            $table->decimal('loyalty_points', 12, 2)->default(0)->comment('Puntos de cashback acumulados');
            $table->enum('segment', ['vip', 'at_risk', 'new', 'interest'])->default('new');
            $table->boolean('is_vip_pricing')->default(false)->comment('Acceso a precios preferenciales');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
