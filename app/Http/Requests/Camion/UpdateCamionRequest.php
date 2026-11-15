<?php

namespace App\Http\Requests\Camion;

use App\Models\Camion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCamionRequest extends FormRequest
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
        /** @var Camion $camion */
        $camion = $this->route('camion');

        return [
            'patente' => ['sometimes', 'string', 'max:10', Rule::unique('camiones', 'patente')->ignore($camion)],
            'marca' => ['sometimes', 'string', 'max:255'],
            'modelo' => ['sometimes', 'string', 'max:255'],
            'anio' => ['sometimes', 'integer', 'between:1980,'.(now()->year + 1)],
            'capacidad_kg' => ['sometimes', 'numeric', 'gt:0', 'max:999999.99'],
            'estado_operativo' => ['sometimes', Rule::in(['disponible', 'en_ruta', 'mantenimiento', 'inactivo'])],
            'chofer_actual_id' => [
                'sometimes', 'nullable', 'integer', 'exists:choferes,id',
                Rule::unique('camiones', 'chofer_actual_id')->ignore($camion),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->patente)) {
            $this->merge(['patente' => strtoupper(trim($this->patente))]);
        }
    }
}
