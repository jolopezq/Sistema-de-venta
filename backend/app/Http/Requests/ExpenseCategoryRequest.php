<?php

namespace App\Http\Requests;

use App\Models\ExpenseCategory;
use App\Support\NameKey;
use Illuminate\Foundation\Http\FormRequest;

class ExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => NameKey::tidy($this->input('name'))]);
        }
        if ($this->has('group_name')) {
            $this->merge(['group_name' => NameKey::tidy($this->input('group_name')) ?: null]);
        }
    }

    public function rules(): array
    {
        $current = $this->route('expenseCategory');

        return [
            'name'        => ['required', 'string', 'max:100', function (string $attribute, $value, \Closure $fail) use ($current) {
                // Sin duplicados escritos distinto ("Gas licuado" = "gas  Licuado" = "Gás licuado")
                $key = NameKey::make($value);
                $existing = ExpenseCategory::query()
                    ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
                    ->get(['id', 'name', 'is_active'])
                    ->first(fn ($c) => NameKey::make($c->name) === $key);
                if ($existing) {
                    $fail($existing->is_active
                        ? "Ya existe la categoría «{$existing->name}»."
                        : "Ya existe la categoría «{$existing->name}», desactivada: actívala en Finanzas → Categorías.");
                }
            }],
            'section'     => ['required', 'string', 'in:cost_of_sales,operating,financial,taxes'],
            'cost_behavior' => ['nullable', 'in:fixed,variable'],
            'group_name'  => ['nullable', 'string', 'max:100'],
            'sort_order'  => ['nullable', 'integer'],
            'is_active'   => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'    => 'El nombre de la categoría es obligatorio.',
            'section.required' => 'La sección contable es obligatoria.',
            'section.in'       => 'La sección contable no es válida.',
        ];
    }
}
