<?php

namespace App\Http\Requests\Dispositivo;

use App\Models\Dispositivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnunciarDispositivoRequest extends FormRequest
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
            'codigo_hardware' => ['required', 'string', 'max:64'],
            'codigo_vinculacion' => ['required', 'string', 'max:32'],
            'firmware_version' => ['nullable', 'string', 'max:32'],
            'conectividad' => ['nullable', Rule::in(Dispositivo::CONECTIVIDADES)],
            'tipo_sensor' => ['nullable', Rule::in(Dispositivo::TIPOS_SENSOR)],
        ];
    }
}
