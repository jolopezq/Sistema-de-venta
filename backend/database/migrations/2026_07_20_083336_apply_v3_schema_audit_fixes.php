<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create sale_item_options table
        Schema::create('sale_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->constrained('sale_items')->onDelete('cascade');
            $table->foreignId('option_id')->constrained('options')->onDelete('cascade');
            $table->foreignId('option_group_id')->constrained('option_groups')->onDelete('cascade');
            $table->string('option_name_snapshot');
            $table->decimal('additional_price_snapshot', 10, 2);
            $table->decimal('quantity', 10, 4)->default(1);
            $table->timestamps();
        });

        // Remove topping_modifications JSON column from sale_items
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('topping_modifications');
        });

        // 2. Add reference_type and reference_id to inventory_movements
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->enum('reference_type', ['sale_item', 'sale_item_option', 'purchase_order', 'manual'])->nullable()->after('performed_by');
            $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');
            $table->index(['reference_type', 'reference_id']);
        });

        // 3. Rename quantity_required to quantity_delta in option_recipes
        Schema::table('option_recipes', function (Blueprint $table) {
            $table->renameColumn('quantity_required', 'quantity_delta');
        });

        // 4. Create platform_catalog_mappings table
        Schema::create('platform_catalog_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 50); // 'pedidosya', 'rappi', etc.
            $table->enum('entity_type', ['product', 'option']);
            $table->string('external_id');
            $table->unsignedBigInteger('internal_id'); // FK lógica a products.id u options.id
            $table->timestamps();

            $table->unique(['platform', 'entity_type', 'external_id'], 'uq_platform_entity');
        });

        // 5. Minor fixes
        // Add sort_order to products
        Schema::table('products', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('is_active');
        });

        // Add unique constraints
        Schema::table('option_group_product', function (Blueprint $table) {
            $table->unique(['product_id', 'option_group_id'], 'uq_product_group');
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->unique(['product_id', 'ingredient_id'], 'uq_product_ingredient');
        });

        Schema::table('option_recipes', function (Blueprint $table) {
            $table->unique(['option_id', 'ingredient_id'], 'uq_option_ingredient');
        });
    }

    public function down(): void
    {
        // Remove unique constraints
        Schema::table('option_recipes', function (Blueprint $table) {
            $table->dropUnique('uq_option_ingredient');
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropUnique('uq_product_ingredient');
        });

        Schema::table('option_group_product', function (Blueprint $table) {
            $table->dropUnique('uq_product_group');
        });

        // Remove sort_order from products
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });

        // Drop platform_catalog_mappings
        Schema::dropIfExists('platform_catalog_mappings');

        // Rename quantity_delta back to quantity_required in option_recipes
        Schema::table('option_recipes', function (Blueprint $table) {
            $table->renameColumn('quantity_delta', 'quantity_required');
        });

        // Remove reference fields from inventory_movements
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex(['reference_type', 'reference_id']);
            $table->dropColumn(['reference_type', 'reference_id']);
        });

        // Restore topping_modifications to sale_items
        Schema::table('sale_items', function (Blueprint $table) {
            $table->json('topping_modifications')->nullable();
        });

        // Drop sale_item_options table
        Schema::dropIfExists('sale_item_options');
    }
};
