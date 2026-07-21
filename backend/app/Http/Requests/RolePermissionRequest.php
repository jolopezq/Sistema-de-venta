<?php

namespace App\Http\Requests;

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
            'permissions.*.role' => ['required', 'string', 'in:super_admin,admin,cashier'],
            'permissions.*.module' => ['required', 'string'],
            'permissions.*.access_level' => ['required', 'string', 'in:none,read,edit'],
        ];
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
            'permissions.*.role.in' => 'El rol seleccionado no es válido.',
            'permissions.*.module.required' => 'El módulo es obligatorio.',
            'permissions.*.access_level.required' => 'El nivel de acceso es obligatorio.',
            'permissions.*.access_level.in' => 'El nivel de acceso no es válido.',
        ];
    }
}
