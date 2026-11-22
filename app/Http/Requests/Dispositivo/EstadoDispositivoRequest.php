<?php

namespace App\Http\Requests\Dispositivo;

use Illuminate\Foundation\Http\FormRequest;

class EstadoDispositivoRequest extends FormRequest
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
            'codigo_hardware' => ['required', 'string', 'max:64'],
            'codigo_vinculacion' => ['required', 'string', 'max:32'],
        ];
    }
}
