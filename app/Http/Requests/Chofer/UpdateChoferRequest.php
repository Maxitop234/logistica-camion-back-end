<?php

namespace App\Http\Requests\Chofer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChoferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'string', 'max:255'],
            'licencia_clase' => ['sometimes', Rule::in(['A1', 'A2', 'A3', 'A4', 'A5', 'B', 'C', 'D', 'E', 'F'])],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:20'],
            'disponible' => ['sometimes', 'boolean'],
        ];
    }
}
