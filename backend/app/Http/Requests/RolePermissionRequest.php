<?php

namespace App\Http\Requests;

use App\Services\PermissionService;
use Illuminate\Foundation\Http\FormRequest;

class RolePermissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Must be super_admin to update permissions, which is handled by middleware
        return true; 
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array'],
            'permissions.*.role' => ['required', 'string', 'in:admin,cashier,kitchen'],
            'permissions.*.module' => ['required', 'string', 'in:' . implode(',', PermissionService::MODULES)],
            'permissions.*.access_level' => ['required', 'string', 'in:none,read,edit'],
        ];
    }

    /**
     * Restricciones de seguridad: techos por rol y unicidad de pares rol-módulo.
     *
     * Topes inamovibles:
     * - super_admin no se envía (siempre edit total).
     * - Ningún rol puede superar el techo definido en PermissionService::CEILINGS.
     * - No se permiten pares {role, module} repetidos en el mismo payload.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $permissions = $this->input('permissions');
            if (!is_array($permissions)) {
                return;
            }

            $seenPairs = [];
            $permissionService = app(PermissionService::class);

            foreach ($permissions as $i => $perm) {
                if (!is_array($perm)) {
                    continue;
                }

                $role = $perm['role'] ?? '';
                $module = $perm['module'] ?? '';
                $level = $perm['access_level'] ?? '';

                // super_admin no se toca desde la interfaz
                if ($role === 'super_admin') {
                    $v->errors()->add(
                        "permissions.{$i}.role",
                        'El Super Admin siempre tiene edición total y no puede modificarse.'
                    );
                    continue;
                }

                // Unicidad de pares rol + módulo
                $pairKey = "{$role}:{$module}";
                if (isset($seenPairs[$pairKey])) {
                    $v->errors()->add(
                        "permissions.{$i}.module",
                        "Par repetido: el rol '{$role}' ya tiene una entrada para el módulo '{$module}'."
                    );
                }
                $seenPairs[$pairKey] = true;

                // Validación contra niveles permitidos por rol y módulo (levelsFor)
                if (in_array($role, ['admin', 'cashier', 'kitchen'], true) && in_array($module, PermissionService::MODULES, true)) {
                    $allowedLevels = $permissionService->levelsFor($role, $module);
                    if (!in_array($level, $allowedLevels, true)) {
                        $v->errors()->add(
                            "permissions.{$i}.access_level",
                            "El nivel '{$level}' no es válido para el rol '{$role}' en el módulo '{$module}'."
                        );
                    }
                }
            }
        });
    }

    /**
     * Custom messages for validation.
     */
    public function messages(): array
    {
        return [
            'permissions.required' => 'La lista de permisos es obligatoria.',
            'permissions.array' => 'La lista de permisos debe ser un arreglo.',
            'permissions.*.role.required' => 'El rol es obligatorio para cada permiso.',
            'permissions.*.role.in' => 'El rol seleccionado no es válido. Solo se permiten admin, cashier y kitchen.',
            'permissions.*.module.required' => 'El módulo es obligatorio.',
            'permissions.*.module.in' => 'El módulo seleccionado no es válido.',
            'permissions.*.access_level.required' => 'El nivel de acceso es obligatorio.',
            'permissions.*.access_level.in' => 'El nivel de acceso no es válido.',
        ];
    }
}
