<?php

namespace App\Http\Requests\Ruta;

use Illuminate\Foundation\Http\FormRequest;

class SincronizarLoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'recolecciones' => ['required', 'array', 'min:1'],
            'recolecciones.*.punto_id' => ['required', 'integer', 'exists:puntos,id'],
            'recolecciones.*.sync_uuid' => ['required', 'uuid'],
            'recolecciones.*.hora_real_recoleccion' => ['required', 'date'],
            'recolecciones.*.observaciones' => ['nullable', 'string', 'max:500'],
        ];
    }
}
