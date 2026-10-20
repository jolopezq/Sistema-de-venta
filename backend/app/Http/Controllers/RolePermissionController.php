<?php

namespace App\Http\Controllers;

use App\Models\RolePermission;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RolePermissionController extends Controller
{
    /**
     * Devuelve todos los permisos, módulos, restricciones, techos y defaults.
     */
    public function index(): JsonResponse
    {
        $permissionService = app(PermissionService::class);
        $levels = [];
        foreach (['kitchen', 'cashier', 'admin'] as $role) {
            foreach (PermissionService::MODULES as $module) {
                $levels[$role][$module] = $permissionService->levelsFor($role, $module);
            }
        }

        return response()->json([
            'permissions'  => RolePermission::all(),
            'modules'      => PermissionService::MODULES,
            'restrictions' => PermissionService::restrictions(),
            'ceilings'     => PermissionService::CEILINGS,
            'defaults'     => PermissionService::DEFAULTS,
            'levels'       => $levels,
        ]);
    }

    /**
     * Actualiza los permisos en lote dentro de una transacción (solo super_admin, por middleware).
     */
    public function update(\App\Http\Requests\RolePermissionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated) {
            foreach ($validated['permissions'] as $perm) {
                RolePermission::updateOrCreate(
                    ['role' => $perm['role'], 'module' => $perm['module']],
                    ['access_level' => $perm['access_level']]
                );
            }
        });

        // Limpiar caché en memoria del servicio
        app(PermissionService::class)->clearCache();

        return response()->json([
            'message'     => 'Matriz de permisos guardada.',
            'permissions' => RolePermission::all(),
        ]);
    }
}
