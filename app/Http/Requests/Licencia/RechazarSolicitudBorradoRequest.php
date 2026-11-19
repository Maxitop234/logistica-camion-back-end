<?php

namespace App\Http\Requests\Licencia;

use Illuminate\Foundation\Http\FormRequest;

class RechazarSolicitudBorradoRequest extends FormRequest
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
            'respuesta_admin' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->respuesta_admin)) {
            $limpio = trim($this->respuesta_admin);
            $this->merge(['respuesta_admin' => $limpio === '' ? null : $limpio]);
        }
    }
}
