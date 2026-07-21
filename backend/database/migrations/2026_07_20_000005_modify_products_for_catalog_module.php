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
        Schema::table('products', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name')->comment('Descripción o lista de ingredientes por defecto');
        });

        Schema::dropIfExists('product_variants');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('size')->comment('Junior, Mediano, Grande, Ohana, etc.');
            $table->decimal('price', 10, 2);
            $table->decimal('vip_price', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
