<?php

namespace App\Http\Requests\Chofer;

use App\Models\Chofer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChoferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('chofer') instanceof Chofer ? $this->route('chofer')->user_id : null)],
            'nombre' => ['sometimes', 'string', 'max:255'],
            'licencia_clase' => ['sometimes', Rule::in(['A1', 'A2', 'A3', 'A4', 'A5', 'B', 'C', 'D', 'E', 'F'])],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:20'],
            'disponible' => ['sometimes', 'boolean'],
        ];
    }
}
