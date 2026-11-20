<?php

namespace App\Http\Requests\Punto;

use App\Models\Punto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSolicitudPuntoRequest extends FormRequest
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
            'direccion' => ['required', 'string', 'min:3', 'max:255'],
            'tipo_punto' => ['required', 'string', Rule::in(Punto::TIPOS_VALIDOS)],
            'latitud' => ['required', 'numeric', 'between:-90,90'],
            'longitud' => ['required', 'numeric', 'between:-180,180'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->observacion)) {
            $limpio = trim($this->observacion);
            $this->merge(['observacion' => $limpio === '' ? null : $limpio]);
        }
    }
}
