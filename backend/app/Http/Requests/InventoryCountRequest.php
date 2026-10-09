<?php

namespace App\Http\Requests;

use App\Models\InventoryCount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Conteo físico (plan de inventario, F3): solo las líneas donde se escribió una cantidad contada. */
class InventoryCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope'                => ['nullable', Rule::in(InventoryCount::SCOPES)],
            'notes'                => ['nullable', 'string', 'max:500'],
            'lines'                => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.ingredient_id' => ['required', 'distinct', Rule::exists('ingredients', 'id')->whereNull('deleted_at')->where('tracks_stock', true)],
            'lines.*.counted'      => ['required', 'numeric', 'min:0', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required'                => 'Escribe la cantidad contada de al menos un insumo.',
            'lines.min'                     => 'Escribe la cantidad contada de al menos un insumo.',
            'lines.*.ingredient_id.exists'  => 'Uno de los insumos ya no existe o no lleva stock.',
            'lines.*.ingredient_id.distinct' => 'Un insumo aparece dos veces en el conteo.',
            'lines.*.counted.required'      => 'Falta la cantidad contada.',
            'lines.*.counted.numeric'       => 'La cantidad contada debe ser un número.',
            'lines.*.counted.min'           => 'La cantidad contada no puede ser negativa.',
            'lines.*.counted.max'           => 'La cantidad contada es demasiado grande.',
            'scope.in'                      => 'El alcance del conteo no es válido.',
        ];
    }
}
