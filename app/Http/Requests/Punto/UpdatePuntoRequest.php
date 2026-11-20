<?php

namespace App\Http\Requests\Punto;

use App\Models\Punto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePuntoRequest extends FormRequest
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
        /** @var Punto $punto */
        $punto = $this->route('punto');

        return [
            'nombre' => ['sometimes', 'string', 'min:3', 'max:255'],
            'direccion' => ['sometimes', 'string', 'min:3', 'max:255'],
            'tipo_punto' => ['sometimes', 'string', Rule::in(Punto::TIPOS_VALIDOS)],
            'latitud' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitud' => ['sometimes', 'numeric', 'between:-180,180'],
            'porcentaje_llenado' => ['sometimes', 'integer', 'between:0,100'],
            'altura_contenedor_cm' => ['sometimes', 'numeric', 'between:30,500'],
            'sensor_codigo' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
                Rule::unique('puntos', 'sensor_codigo')->ignore($punto),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->sensor_codigo)) {
            $limpio = trim($this->sensor_codigo);
            $this->merge(['sensor_codigo' => $limpio === '' ? null : $limpio]);
        }
    }
}
