<?php

namespace App\Http\Requests\Punto;

use App\Concerns\ZonaDeOperacionValidationRules;
use App\Models\Punto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSolicitudPuntoRequest extends FormRequest
{
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
        return [
            'nombre' => ['required', 'string', 'min:3', 'max:255'],
            'nombre_automatico' => ['sometimes', 'boolean'],
            'direccion' => ['required', 'string', 'min:3', 'max:255'],
            'tipo_punto' => ['required', 'string', Rule::in(Punto::TIPOS_VALIDOS)],
            'latitud' => $this->latitudEnZonaRules(),
            'longitud' => $this->longitudEnZonaRules(),
            'observacion' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->zonaDeOperacionMessages();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->observacion)) {
            $limpio = trim($this->observacion);
            $this->merge(['observacion' => $limpio === '' ? null : $limpio]);
        }
    }
}
