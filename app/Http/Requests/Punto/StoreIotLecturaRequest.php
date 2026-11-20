<?php

namespace App\Http\Requests\Punto;

use Illuminate\Foundation\Http\FormRequest;

class StoreIotLecturaRequest extends FormRequest
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
            'distancia_cm' => ['nullable', 'required_without:porcentaje_llenado', 'numeric', 'between:0,500'],
            'porcentaje_llenado' => ['nullable', 'required_without:distancia_cm', 'integer', 'between:0,100'],
            'bateria_voltaje' => ['nullable', 'numeric', 'between:0,24'],
            'medido_en' => ['nullable', 'date'],
            'lectura_uuid' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'distancia_cm.required_without' => 'Debes enviar distancia_cm o porcentaje_llenado.',
            'porcentaje_llenado.required_without' => 'Debes enviar porcentaje_llenado o distancia_cm.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->lectura_uuid)) {
            $limpio = trim($this->lectura_uuid);
            $this->merge(['lectura_uuid' => $limpio === '' ? null : $limpio]);
        }
    }
}
