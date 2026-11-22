<?php

namespace App\Http\Requests\Punto;

use App\Concerns\ZonaDeOperacionValidationRules;
use App\Enums\EstadoLlenado;
use App\Models\Punto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePuntoRequest extends FormRequest
{
    use ResuelveSectorDelPunto;
    use ZonaDeOperacionValidationRules;

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
            'nombre_automatico' => ['sometimes', 'boolean'],
            'direccion' => ['sometimes', 'string', 'min:3', 'max:255'],
            'tipo_punto' => ['sometimes', 'string', Rule::in(Punto::TIPOS_VALIDOS)],
            'sector_id' => ['sometimes', 'nullable', 'integer', Rule::exists('sectores', 'id')->where('activo', true)],
            'sector' => ['sometimes', 'nullable', 'string', 'max:150'],
            'tipo_material' => ['sometimes', 'nullable', 'string', Rule::in(StorePuntoRequest::TIPOS_MATERIAL)],
            'latitud' => $this->latitudEnZonaRules(obligatoria: false),
            'longitud' => $this->longitudEnZonaRules(obligatoria: false),
            'nota' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'requiere_revision' => ['sometimes', 'boolean'],
            'porcentaje_llenado' => ['sometimes', 'integer', 'between:0,100'],
            'estado_llenado' => ['sometimes', 'string', Rule::enum(EstadoLlenado::class)],
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->zonaDeOperacionMessages(),
            'sector_id.exists' => 'El sector indicado no existe o está inactivo.',
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
