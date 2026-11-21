<?php

namespace App\Http\Requests\Punto;

use App\Models\Punto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePuntoRequest extends FormRequest
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
            'nombre' => ['required', 'string', 'min:3', 'max:255'],
            'nombre_automatico' => ['sometimes', 'boolean'],
            'direccion' => ['required', 'string', 'min:3', 'max:255'],
            'tipo_punto' => ['required', 'string', Rule::in(Punto::TIPOS_VALIDOS)],
            'sector' => ['sometimes', 'nullable', 'string', Rule::in(Punto::SECTORES_VALIDOS)],
            'latitud' => ['required', 'numeric', 'between:-90,90'],
            'longitud' => ['required', 'numeric', 'between:-180,180'],
            'porcentaje_llenado' => ['sometimes', 'integer', 'between:0,100'],
            'altura_contenedor_cm' => ['sometimes', 'numeric', 'between:30,500'],
            'sensor_codigo' => ['sometimes', 'nullable', 'string', 'max:64', 'unique:puntos,sensor_codigo'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->sensor_codigo)) {
            $limpio = trim($this->sensor_codigo);
            $this->merge(['sensor_codigo' => $limpio === '' ? null : $limpio]);
        }
        if (is_string($this->sector) && trim($this->sector) === '') {
            $this->merge(['sector' => null]);
        }
    }
}
