<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserPermission;
use App\Services\AuditLogger;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Permisos de una persona (Usuarios → editar persona). Solo super admin, por middleware.
 * Cada módulo informa el nivel de su rol, la excepción propia (si tiene) y el nivel efectivo.
 */
class UserPermissionController extends Controller
{
    /** Nombres para la auditoría y los mensajes. */
    private const MODULE_NAMES = [
        'sales_history'        => 'Ventas de días pasados',
        'register_corrections' => 'Corregir cajas cerradas',
        'finance'              => 'Finanzas',
        'pos_discounts'        => 'Descuentos en caja',
        'attendance'           => 'Asistencia y sueldos',
    ];

    private const LEVEL_NAMES = ['none' => 'Sin acceso', 'read' => 'Ver', 'edit' => 'Editar'];

    public function __construct(private readonly PermissionService $permissions)
    {
    }

    public function show(int $user): JsonResponse
    {
        return response()->json($this->payload(User::findOrFail($user)));
    }

    /**
     * Guarda las excepciones enviadas. access_level null = «como su rol» (borra la excepción).
     */
    public function update(Request $request, int $user): JsonResponse
    {
        $target = User::findOrFail($user);
        if ($target->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'permissions' => 'El Super Admin ya tiene todos los permisos.',
            ]);
        }

        $validated = $request->validate([
            'permissions'                => ['required', 'array'],
            'permissions.*.module'       => ['required', 'string', 'distinct', 'in:' . implode(',', PermissionService::MODULES)],
            'permissions.*.access_level' => ['nullable', 'string', 'in:none,read,edit'],
        ]);

        foreach ($validated['permissions'] as $i => $perm) {
            $level = $perm['access_level'] ?? null;
            if ($level !== null && !in_array($level, $this->permissions->levelsFor($target->role, $perm['module']), true)) {
                throw ValidationException::withMessages([
                    "permissions.{$i}.access_level" => sprintf(
                        '«%s» no admite «%s» para el rol de %s.',
                        self::MODULE_NAMES[$perm['module']] ?? $perm['module'],
                        self::LEVEL_NAMES[$level],
                        $target->name
                    ),
                ]);
            }
        }

        $before = UserPermission::where('user_id', $target->id)->pluck('access_level', 'module')->all();
        $changes = [];

        DB::transaction(function () use ($validated, $target, $request, $before, &$changes) {
            foreach ($validated['permissions'] as $perm) {
                $module = $perm['module'];
                $level = $perm['access_level'] ?? null;
                $old = $before[$module] ?? null;
                if ($old === $level) {
                    continue;
                }
                if ($level === null) {
                    UserPermission::where('user_id', $target->id)->where('module', $module)->delete();
                } else {
                    UserPermission::updateOrCreate(
                        ['user_id' => $target->id, 'module' => $module],
                        ['access_level' => $level, 'granted_by' => $request->user()->id]
                    );
                }
                $changes[] = sprintf(
                    '%s: %s → %s',
                    self::MODULE_NAMES[$module] ?? $module,
                    $old === null ? 'como su rol' : self::LEVEL_NAMES[$old],
                    $level === null ? 'como su rol' : self::LEVEL_NAMES[$level]
                );
            }
        });

        if ($changes) {
            AuditLogger::log(
                'user_permissions_changed',
                'Users',
                "Permisos de {$target->name} cambiados por {$request->user()->name}. " . implode('; ', $changes) . '.',
                $request->user()->id
            );
        }

        $this->permissions->clearCache();

        return response()->json($this->payload($target) + ['message' => 'Permisos guardados.']);
    }

    private function payload(User $user): array
    {
        $this->permissions->clearCache();
        $overrides = UserPermission::where('user_id', $user->id)->pluck('access_level', 'module')->all();

        $modules = [];
        foreach (PermissionService::MODULES as $module) {
            $modules[$module] = [
                'role_level' => $this->permissions->roleLevel($user->role, $module),
                'override'   => $user->isSuperAdmin() ? null : ($overrides[$module] ?? null),
                'effective'  => $this->permissions->level($user, $module),
                'allowed'    => $user->isSuperAdmin() ? ['edit'] : $this->permissions->levelsFor($user->role, $module),
            ];
        }

        return [
            'user_id'        => $user->id,
            'role'           => $user->role,
            'person_modules' => PermissionService::PERSON_MODULES,
            'modules'        => $modules,
        ];
    }
}
