<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
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
