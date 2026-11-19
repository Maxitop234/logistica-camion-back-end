<?php

namespace App\Http\Requests\Licencia;

use Illuminate\Foundation\Http\FormRequest;

class StoreSolicitudBorradoLicenciaRequest extends FormRequest
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
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Debes indicar el motivo de la solicitud.',
            'motivo.min' => 'El motivo debe tener al menos 5 caracteres.',
            'motivo.max' => 'El motivo no puede superar los 500 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->motivo)) {
            $this->merge(['motivo' => trim($this->motivo)]);
        }
    }
}
