<?php

namespace App\Http\Requests\Punto;

use Illuminate\Foundation\Http\FormRequest;

class RechazarSolicitudPuntoRequest extends FormRequest
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
            'motivo_rechazo' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo_rechazo.required' => 'Debes indicar el motivo del rechazo.',
            'motivo_rechazo.min' => 'El motivo del rechazo debe tener al menos 5 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->motivo_rechazo)) {
            $this->merge(['motivo_rechazo' => trim($this->motivo_rechazo)]);
        }
    }
}
