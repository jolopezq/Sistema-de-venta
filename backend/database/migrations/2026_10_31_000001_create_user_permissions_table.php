<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permisos por persona: una excepción por usuario y módulo encima de la matriz del rol
 * (docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md §3.1). Nunca supera el techo del rol: lo aplica PermissionService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module', 40);
            $table->string('access_level', 10)->comment('none, read o edit');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
