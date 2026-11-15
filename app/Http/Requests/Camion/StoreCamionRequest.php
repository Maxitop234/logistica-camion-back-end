<?php

namespace App\Http\Requests\Camion;

use Illuminate\Foundation\Http\FormRequest;

class StoreCamionRequest extends FormRequest
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
            'patente' => ['required', 'string', 'max:10', 'unique:camiones,patente'],
            'marca' => ['required', 'string', 'max:255'],
            'modelo' => ['required', 'string', 'max:255'],
            'anio' => ['required', 'integer', 'between:1980,'.(now()->year + 1)],
            'capacidad_kg' => ['required', 'numeric', 'gt:0', 'max:999999.99'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->patente)) {
            $this->merge(['patente' => strtoupper(trim($this->patente))]);
        }
    }
}
