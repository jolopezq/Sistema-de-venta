<?php

namespace App\Services;

use App\Models\RolePermission;
use App\Models\User;
use InvalidArgumentException;

/**
 * Servicio central de permisos — modelo techo + matriz dinámica (Opción C v2).
 *
 * Fórmula:
 *   nivel_efectivo(usuario, módulo) =
 *       si es super_admin  => 'edit'
 *       si no              => mín( techo[rol][módulo],
 *                                  matriz[rol][módulo] ?? por_defecto[rol][módulo] )
 *
 *   puede(usuario, módulo, requerido) = nivel_efectivo >= requerido (none < read < edit)
 */
class PermissionService
{
    /** Caché por request para no repetir consultas. */
    private array $cache = [];

    /** Valores numéricos de cada nivel de acceso. */
    public const LEVEL_VALUES = [
        'none' => 0,
        'read' => 1,
        'edit' => 2,
    ];

    /** Lista completa de los 12 módulos del sistema. */
    public const MODULES = [
        'pos',
        'voids',
        'register_self',
        'register_others',
        'inventory',
        'catalog',
        'crm_read',
        'crm_config',
        'delivery_ops',
        'reports',
        'users',
        'audit',
    ];

    /**
     * Niveles válidos y reales que la API y las rutas soportan para cada módulo.
     * Si un módulo no tiene rutas de lectura diferenciadas (ej. inventory, voids, crm_config),
     * solo admite 'none' y 'edit'.
     */
    public const ALLOWED_LEVELS = [
        'pos'             => ['none', 'read', 'edit'],
        'register_self'   => ['none', 'read', 'edit'],
        'delivery_ops'    => ['none', 'read', 'edit'],
        'crm_read'        => ['none', 'read'],
        'voids'           => ['none', 'edit'],
        'register_others' => ['none', 'read', 'edit'],
        'catalog'         => ['none', 'read', 'edit'],
        'inventory'       => ['none', 'edit'],
        'crm_config'      => ['none', 'edit'],
        'reports'         => ['none', 'read'],
        'users'           => ['none'],
        'audit'           => ['none'],
    ];

    /**
     * Techo inamovible por rol y módulo (el límite máximo al que puede aspirar cada rol).
     * 'none' = el rol no puede tener acceso a ese módulo bajo ninguna circunstancia.
     */
    public const CEILINGS = [
        'kitchen' => [
            'delivery_ops'    => 'edit',
            'pos'             => 'none',
            'voids'           => 'none',
            'register_self'   => 'none',
            'register_others' => 'none',
            'inventory'       => 'none',
            'catalog'         => 'none',
            'crm_read'        => 'none',
            'crm_config'      => 'none',
            'reports'         => 'none',
            'users'           => 'none',
            'audit'           => 'none',
        ],
        'cashier' => [
            'pos'             => 'edit',
            'register_self'   => 'edit',
            'delivery_ops'    => 'edit',
            'crm_read'        => 'read',
            'voids'           => 'none',
            'register_others' => 'none',
            'inventory'       => 'none',
            'catalog'         => 'none',
            'crm_config'      => 'none',
            'reports'         => 'none',
            'users'           => 'none',
            'audit'           => 'none',
        ],
        'admin' => [
            'pos'             => 'edit',
            'voids'           => 'edit',
            'register_self'   => 'edit',
            'register_others' => 'edit',
            'inventory'       => 'edit',
            'catalog'         => 'edit',
            'crm_read'        => 'read',
            'crm_config'      => 'edit',
            'delivery_ops'    => 'edit',
            'reports'         => 'read',
            'users'           => 'none',
            'audit'           => 'none',
        ],
    ];

    /**
     * Valores por defecto si la fila no existe en la base de datos.
     * Siempre DEFAULTS <= CEILINGS.
     */
    public const DEFAULTS = [
        'kitchen' => [
            'delivery_ops'    => 'edit',
            'pos'             => 'none',
            'voids'           => 'none',
            'register_self'   => 'none',
            'register_others' => 'none',
            'inventory'       => 'none',
            'catalog'         => 'none',
            'crm_read'        => 'none',
            'crm_config'      => 'none',
            'reports'         => 'none',
            'users'           => 'none',
            'audit'           => 'none',
        ],
        'cashier' => [
            'pos'             => 'edit',
            'register_self'   => 'edit',
            'delivery_ops'    => 'edit',
            'crm_read'        => 'read',
            'voids'           => 'none',
            'register_others' => 'none',
            'inventory'       => 'none',
            'catalog'         => 'none',
            'crm_config'      => 'none',
            'reports'         => 'none',
            'users'           => 'none',
            'audit'           => 'none',
        ],
        'admin' => [
            'pos'             => 'edit',
            'voids'           => 'edit',
            'register_self'   => 'edit',
            'register_others' => 'edit',
            'inventory'       => 'edit',
            'catalog'         => 'edit',
            'crm_read'        => 'read',
            'crm_config'      => 'edit',
            'delivery_ops'    => 'edit',
            'reports'         => 'read',
            'users'           => 'none',
            'audit'           => 'none',
        ],
    ];

    /**
     * Calcula el nivel efectivo (none, read, edit) de un usuario para un módulo.
     *
     * @throws InvalidArgumentException si el módulo no existe en self::MODULES
     */
    public function level(User $user, string $module): string
    {
        if (!in_array($module, self::MODULES, true)) {
            throw new InvalidArgumentException("Módulo de permisos desconocido: '{$module}'");
        }

        // super_admin siempre tiene edición total incondicional
        if ($user->isSuperAdmin()) {
            return 'edit';
        }

        $role = $user->role;
        $ceiling = self::CEILINGS[$role][$module] ?? 'none';

        // Si el techo es 'none', ningún valor de base de datos puede otorgar acceso
        if ($ceiling === 'none') {
            return 'none';
        }

        $configured = $this->getConfiguredLevel($role, $module);
        $candidate = $configured ?? (self::DEFAULTS[$role][$module] ?? 'none');

        // Normalización: si el valor configurado no es un nivel válido para este módulo,
        // se toma el nivel válido más alto que no lo supere (ej: inventory 'read' -> 'none').
        $allowed = self::ALLOWED_LEVELS[$module] ?? ['none'];
        if (!in_array($candidate, $allowed, true)) {
            $candidateVal = self::LEVEL_VALUES[$candidate] ?? 0;
            $best = 'none';
            foreach ($allowed as $validLvl) {
                $val = self::LEVEL_VALUES[$validLvl] ?? 0;
                if ($val <= $candidateVal && $val >= (self::LEVEL_VALUES[$best] ?? 0)) {
                    $best = $validLvl;
                }
            }
            $candidate = $best;
        }

        $ceilingVal = self::LEVEL_VALUES[$ceiling] ?? 0;
        $candidateVal = self::LEVEL_VALUES[$candidate] ?? 0;

        // Retorna mín(techo, candidato)
        return $candidateVal < $ceilingVal ? $candidate : $ceiling;
    }

    /**
     * Devuelve los niveles válidos para un rol y módulo específicos,
     * filtrando solo aquellos niveles soportados por el módulo que además
     * no superen el techo (CEILING) del rol.
     *
     * @return string[]
     */
    public function levelsFor(string $role, string $module): array
    {
        $ceiling = self::CEILINGS[$role][$module] ?? 'none';
        $ceilingVal = self::LEVEL_VALUES[$ceiling] ?? 0;
        $allowed = self::ALLOWED_LEVELS[$module] ?? ['none'];

        return array_values(array_filter($allowed, function ($lvl) use ($ceilingVal) {
            return (self::LEVEL_VALUES[$lvl] ?? 0) <= $ceilingVal;
        }));
    }

    /**
     * Valida si un usuario cumple con el nivel mínimo requerido en un módulo.
     *
     * @throws InvalidArgumentException si el nivel o módulo son desconocidos
     */
    public function can(User $user, string $module, string $requiredLevel = 'read'): bool
    {
        if (!array_key_exists($requiredLevel, self::LEVEL_VALUES)) {
            throw new InvalidArgumentException("Nivel de permisos desconocido: '{$requiredLevel}'");
        }

        $actualLevel = $this->level($user, $module);

        return (self::LEVEL_VALUES[$actualLevel] ?? 0) >= (self::LEVEL_VALUES[$requiredLevel] ?? 0);
    }

    /**
     * Genera un mapa completo de permisos efectivos {módulo => nivel} para el usuario.
     *
     * @return array<string, string>
     */
    public function effectiveFor(User $user): array
    {
        if ($user->isSuperAdmin()) {
            $effective = [];
            foreach (self::MODULES as $mod) {
                $effective[$mod] = 'edit';
            }
            return $effective;
        }

        $this->warmCacheForRole($user->role);

        $effective = [];
        foreach (self::MODULES as $mod) {
            $effective[$mod] = $this->level($user, $mod);
        }

        return $effective;
    }

    /**
     * Devuelve las restricciones (módulos donde techo es 'none') para compatibilidad con el frontend.
     *
     * @return array<string, string[]>
     */
    public static function restrictions(): array
    {
        $restrictions = [];
        foreach (self::CEILINGS as $role => $mods) {
            $blocked = [];
            foreach ($mods as $mod => $ceil) {
                if ($ceil === 'none') {
                    $blocked[] = $mod;
                }
            }
            $restrictions[$role] = $blocked;
        }
        return $restrictions;
    }

    /**
     * Consulta el nivel configurado en DB con caché por request.
     */
    private function getConfiguredLevel(string $role, string $module): ?string
    {
        $key = "{$role}:{$module}";

        if (!array_key_exists($key, $this->cache)) {
            $perm = RolePermission::where('role', $role)
                ->where('module', $module)
                ->first();
            $this->cache[$key] = $perm ? $perm->access_level : null;
        }

        return $this->cache[$key];
    }

    /**
     * Precarga todos los registros del rol en memoria para evitar consultas individuales.
     */
    private function warmCacheForRole(string $role): void
    {
        $perms = RolePermission::where('role', $role)->get();
        foreach ($perms as $p) {
            $this->cache["{$role}:{$p->module}"] = $p->access_level;
        }
        foreach (self::MODULES as $mod) {
            $key = "{$role}:{$mod}";
            if (!array_key_exists($key, $this->cache)) {
                $this->cache[$key] = null;
            }
        }
    }

    /**
     * Limpia la caché en memoria (esencial para tests).
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}
