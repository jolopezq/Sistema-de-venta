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
        // En SQLite y MySQL permitimos que role sea string/enum con soporte para 'kitchen'
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 50)->default('cashier')->change();
        });

        Schema::table('role_permissions', function (Blueprint $table) {
            $table->string('role', 50)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['super_admin', 'admin', 'cashier'])->default('cashier')->change();
        });

        Schema::table('role_permissions', function (Blueprint $table) {
            $table->enum('role', ['super_admin', 'admin', 'cashier'])->change();
        });
    }
};
