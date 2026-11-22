<?php

namespace App\Http\Requests\Dispositivo;

use App\Models\Dispositivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActualizarDispositivoRequest extends FormRequest
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
            'tipo_sensor' => ['sometimes', Rule::in(Dispositivo::TIPOS_SENSOR)],
            'altura_contenedor_cm' => ['sometimes', 'numeric', 'between:30,500'],
            'distancia_lleno_cm' => ['sometimes', 'numeric', 'between:0,200'],
            'intervalo_lectura_seg' => ['sometimes', 'integer', 'between:60,86400'],
            'notas' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
