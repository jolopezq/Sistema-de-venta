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
        Schema::table('option_groups', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('max_selections');
        });

        Schema::table('options', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
            $table->integer('sort_order')->default(0)->after('is_default');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('options', function (Blueprint $table) {
            $table->dropColumn(['is_default', 'sort_order']);
        });

        Schema::table('option_groups', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
