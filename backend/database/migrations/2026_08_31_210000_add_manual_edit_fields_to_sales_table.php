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
            $table->foreignId('edited_by')->nullable()->after('voided_by')->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('edited_by');
            $table->string('edit_reason')->nullable()->after('edited_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edited_by');
            $table->dropColumn(['edited_at', 'edit_reason']);
        });
    }
};
