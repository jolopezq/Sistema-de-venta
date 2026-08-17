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
            $table->enum('preparation_status', ['received', 'preparing', 'ready', 'delivered'])
                  ->default('received')
                  ->after('status')
                  ->comment('Estado en la cola de preparación (KDS)');
            $table->timestamp('preparation_started_at')->nullable()->after('preparation_status');
            $table->timestamp('ready_at')->nullable()->after('preparation_started_at');
            $table->timestamp('delivered_at')->nullable()->after('ready_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn([
                'preparation_status',
                'preparation_started_at',
                'ready_at',
                'delivered_at',
            ]);
        });
    }
};
