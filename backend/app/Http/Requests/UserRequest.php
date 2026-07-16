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
            'role' => ['required', 'string', 'in:admin,cashier'],
            'pin' => [
                $isUpdate ? 'nullable' : 'required',
                'string',
                'size:4',
                'regex:/^[0-9]+$/', // Solo dígitos
                'unique:users,pin,' . $userId,
            ],
        ];
    }

    /**
     * Custom messages for validation.
     */
    public function messages(): array
    {
        return [
            'pin.regex' => 'El PIN debe contener únicamente números.',
            'pin.size' => 'El PIN debe ser exactamente de 4 dígitos.',
            'pin.unique' => 'Este PIN ya está registrado en el sistema por otro usuario.',
        ];
    }
}
