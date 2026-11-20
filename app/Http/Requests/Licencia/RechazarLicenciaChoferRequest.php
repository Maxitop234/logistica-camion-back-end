<?php

namespace App\Http\Requests\Licencia;

use Illuminate\Foundation\Http\FormRequest;

class RechazarLicenciaChoferRequest extends FormRequest
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
            'motivo' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Debes indicar el motivo del rechazo.',
            'motivo.min' => 'El motivo del rechazo debe tener al menos 5 caracteres.',
            'motivo.max' => 'El motivo del rechazo no puede superar los 255 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->motivo)) {
            $this->merge(['motivo' => trim($this->motivo)]);
        }
    }
}
