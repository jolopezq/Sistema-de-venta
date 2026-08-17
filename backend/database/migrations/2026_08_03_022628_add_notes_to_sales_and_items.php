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
        Schema::table('sales', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('source');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->text('item_note')->nullable()->after('subtotal');
            $table->json('allergen_flags')->nullable()->after('item_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['item_note', 'allergen_flags']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
