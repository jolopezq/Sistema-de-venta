<?php

namespace App\Http\Middleware;

use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware parametrizado que valida el acceso por módulo y nivel.
 *
 * Uso en rutas:
 *   ->middleware('perm:catalog,edit')   — requiere edición en catálogo
 *   ->middleware('perm:inventory,read') — requiere lectura en inventario
 *   ->middleware('perm:pos')            — requiere lectura en POS (nivel por defecto)
 */
class CheckModulePermission
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {}

    /**
     * @param  string  $module  Identificador del módulo (ej. 'catalog', 'inventory')
     * @param  string  $level   Nivel mínimo requerido: 'read' (por defecto) o 'edit'
     */
    public function handle(Request $request, Closure $next, string $module, string $level = 'read'): Response
    {
        $user = $request->user();

        if (!$user || !$this->permissions->can($user, $module, $level)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para acceder a este módulo.',
                'reason' => 'permission_denied',
                'errors' => [],
                'code' => 403,
            ], 403);
        }

        return $next($request);
    }
}
