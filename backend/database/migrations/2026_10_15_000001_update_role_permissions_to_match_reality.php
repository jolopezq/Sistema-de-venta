<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sincroniza la tabla role_permissions con la matriz de referencia v2 (docs/PLAN_ROLES_PERMISOS.md §5).
     * Se escriben los valores de forma literal con DB::table()->upsert() para no depender de modelos Eloquent
     * ni de constantes de clases que puedan evolucionar en el futuro.
     */
    public function up(): void
    {
        $now = now();

        $rows = [
            // 1. Administrador operativo (admin)
            ['role' => 'admin', 'module' => 'pos',             'access_level' => 'edit', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin', 'module' => 'voids',           'access_level' => 'edit', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin', 'module' => 'register_self',   'access_level' => 'edit', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin', 'module' => 'register_others', 'access_level' => 'edit', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin', 'module' => 'inventory',       'access_level' => 'edit', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin', 'module' => 'catalog',         'access_level' => 'edit', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin', 'module' => 'crm_read',        'access_level' => 'read', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin', 'module' => 'crm_config',      'access_level' => 'edit', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin', 'module' => 'delivery_ops',    'access_level' => 'edit', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin', 'module' => 'reports',         'access_level' => 'read', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin', 'module' => 'users',           'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin', 'module' => 'audit',           'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],

            // 2. Cajero (cashier)
            ['role' => 'cashier', 'module' => 'pos',             'access_level' => 'edit', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'cashier', 'module' => 'register_self',   'access_level' => 'edit', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'cashier', 'module' => 'delivery_ops',    'access_level' => 'edit', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'cashier', 'module' => 'crm_read',        'access_level' => 'read', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'cashier', 'module' => 'voids',           'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'cashier', 'module' => 'register_others', 'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'cashier', 'module' => 'inventory',       'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'cashier', 'module' => 'catalog',         'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'cashier', 'module' => 'crm_config',      'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'cashier', 'module' => 'reports',         'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'cashier', 'module' => 'users',           'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'cashier', 'module' => 'audit',           'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],

            // 3. Cocina (kitchen)
            ['role' => 'kitchen', 'module' => 'delivery_ops',    'access_level' => 'edit', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'kitchen', 'module' => 'pos',             'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'kitchen', 'module' => 'voids',           'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'kitchen', 'module' => 'register_self',   'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'kitchen', 'module' => 'register_others', 'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'kitchen', 'module' => 'inventory',       'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'kitchen', 'module' => 'catalog',         'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'kitchen', 'module' => 'crm_read',        'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'kitchen', 'module' => 'crm_config',      'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'kitchen', 'module' => 'reports',         'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'kitchen', 'module' => 'users',           'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'kitchen', 'module' => 'audit',           'access_level' => 'none', 'created_at' => $now, 'updated_at' => $now],
        ];

        DB::table('role_permissions')->upsert(
            $rows,
            ['role', 'module'],
            ['access_level', 'updated_at']
        );
    }

    /**
     * Reverse the migrations.
     *
     * La vuelta atrás operativa consiste en revertir el commit de código;
     * el código anterior ignoraba la tabla role_permissions.
     * Para restaurar la configuración anterior exacta de la tabla, se utiliza el respaldo previo.
     */
    public function down(): void
    {
        // No se eliminan datos destructivamente.
    }
};
