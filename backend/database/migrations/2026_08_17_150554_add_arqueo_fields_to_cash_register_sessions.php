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
        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->json('bill_breakdown')->nullable()->after('difference')
                  ->comment('Desglose de billetes contados {200:2, 100:1, ...}');
            $table->text('diff_note')->nullable()->after('bill_breakdown')
                  ->comment('Nota explicativa en caso de faltante o sobrante');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->dropColumn(['bill_breakdown', 'diff_note']);
        });
    }
};
