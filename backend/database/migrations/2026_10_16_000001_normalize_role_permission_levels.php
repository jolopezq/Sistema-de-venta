<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Normaliza los niveles de acceso de la tabla role_permissions para que coincidan
     * estrictamente con las capacidades reales que soportan las rutas y el backend:
     * - read -> none en inventory, voids y crm_config (módulos sin rutas de solo lectura).
     * - edit -> read en crm_read y reports (módulos que solo tienen rutas de consulta).
     * - Elimina filas huérfanas de delivery_config.
     */
    public function up(): void
    {
        // 1. inventory, voids, crm_config: solo admiten 'none' y 'edit'
        DB::table('role_permissions')
            ->whereIn('module', ['inventory', 'voids', 'crm_config'])
            ->where('access_level', 'read')
            ->update([
                'access_level' => 'none',
                'updated_at'   => now(),
            ]);

        // 2. crm_read, reports: solo admiten 'none' y 'read'
        DB::table('role_permissions')
            ->whereIn('module', ['crm_read', 'reports'])
            ->where('access_level', 'edit')
            ->update([
                'access_level' => 'read',
                'updated_at'   => now(),
            ]);

        // 3. Eliminar filas obsoletas de delivery_config
        DB::table('role_permissions')
            ->where('module', 'delivery_config')
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No destructivo: los valores normalizados no requieren reversión.
    }
};
