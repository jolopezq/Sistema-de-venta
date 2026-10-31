<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

class UserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $userParam = $this->route('user');
        $userId = is_object($userParam) ? $userParam->id : $userParam;
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email,' . $userId,
            ],
            'password' => [
                $isUpdate ? 'nullable' : 'required',
                'string',
                'min:6',
            ],
            'role' => ['required', 'string', 'in:super_admin,admin,cashier,kitchen'],
            'ci' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
            'start_date' => ['nullable', 'date'],
            'photo_url' => ['nullable', 'string', 'url'],
            // Contraseña de quien hace el cambio: obligatoria para nombrar o quitar un Super Admin
            'current_password' => ['nullable', 'string'],
        ];
    }

    /**
     * Protecciones del rol Super Admin (docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md §3.5):
     * nadie cambia su propio rol; no se puede dejar el sistema sin Super Admin; nombrar o quitar
     * un Super Admin pide la contraseña de quien lo hace.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $actor = $this->user();
            $userParam = $this->route('user');
            $target = $userParam ? User::find(is_object($userParam) ? $userParam->id : $userParam) : null;
            $newRole = $this->input('role');
            $oldRole = $target?->role;

            if ($target && $actor && $target->id === $actor->id && $newRole !== $oldRole) {
                $v->errors()->add('role', 'No puedes cambiar tu propio rol.');
                return;
            }

            $promotes = $newRole === 'super_admin' && $oldRole !== 'super_admin';
            $demotes = $oldRole === 'super_admin' && $newRole !== 'super_admin';

            if ($demotes && User::where('role', 'super_admin')->whereKeyNot($target->id)->doesntExist()) {
                $v->errors()->add('role', 'Es el único Super Admin: nombra a otro antes de cambiarle el rol.');
                return;
            }

            if ($promotes || $demotes) {
                $password = (string) $this->input('current_password', '');
                if ($password === '') {
                    $v->errors()->add('current_password', 'Escribe tu contraseña para confirmar este cambio.');
                } elseif (!$actor || !Hash::check($password, $actor->password)) {
                    $v->errors()->add('current_password', 'La contraseña no es correcta.');
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
            'name.required' => 'El nombre es obligatorio.',
            'name.string' => 'El nombre debe ser texto.',
            'name.max' => 'El nombre no puede exceder 255 caracteres.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El formato del correo electrónico no es válido.',
            'email.max' => 'El correo electrónico no puede exceder 255 caracteres.',
            'email.unique' => 'Este correo electrónico ya está registrado.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.string' => 'La contraseña debe ser texto.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
            'role.required' => 'El rol es obligatorio.',
            'role.in' => 'El rol seleccionado no es válido.',
            'ci.max' => 'El CI no puede exceder 20 caracteres.',
            'phone.max' => 'El teléfono no puede exceder 20 caracteres.',
            'start_date.date' => 'La fecha de inicio no es válida.',
            'photo_url.url' => 'La URL de la foto no es válida.',
        ];
    }
}
