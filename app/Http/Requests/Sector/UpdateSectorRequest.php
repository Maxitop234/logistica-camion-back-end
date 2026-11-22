<?php

namespace App\Http\Requests\Sector;

use App\Models\Sector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSectorRequest extends FormRequest
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
        /** @var Sector $sector */
        $sector = $this->route('sector');

        return [
            'nombre' => ['sometimes', 'string', 'min:2', 'max:150', Rule::unique('sectores', 'nombre')->ignore($sector)],
            'dias_recoleccion' => ['sometimes', 'array', 'min:1', 'max:7'],
            'dias_recoleccion.*' => ['integer', 'between:1,7', 'distinct'],
            'periodicidad_semanal' => ['sometimes', 'integer', 'between:1,7'],
            'puntos_esperados' => ['sometimes', 'nullable', 'integer', 'between:0,5000'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.unique' => 'Ya existe un sector con ese nombre.',
            'dias_recoleccion.min' => 'Indica al menos un día de recolección.',
            'dias_recoleccion.*.between' => 'Los días de recolección van de 1 (lunes) a 7 (domingo).',
            'dias_recoleccion.*.distinct' => 'Los días de recolección no se pueden repetir.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->nombre)) {
            $this->merge(['nombre' => trim((string) preg_replace('/\s+/u', ' ', $this->nombre))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function datosSector(): array
    {
        $datos = $this->validated();
        if (array_key_exists('dias_recoleccion', $datos)) {
            $datos['dias_recoleccion'] = Sector::normalizarDias($datos['dias_recoleccion']);
        }

        return $datos;
    }
}
