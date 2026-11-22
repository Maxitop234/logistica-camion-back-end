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
     * Reglas del cuerpo de una lectura. Las usa también el flujo interno del
     * simulador (`RecepcionDeLecturas`), que valida igual que una petición real.
     *
     * @return array<string, array<int, string>>
     */
    public static function reglas(): array
    {
        return [
            'distancia_cm' => ['nullable', 'required_without_all:porcentaje_llenado,error', 'numeric', 'between:0,1000'],
            'error' => ['nullable', 'string', 'max:32'],
            'porcentaje_llenado' => ['nullable', 'integer', 'between:0,100'],
            'bateria_voltaje' => ['nullable', 'numeric', 'between:0,24'],
            'rssi_dbm' => ['nullable', 'integer', 'between:-130,0'],
            'firmware_version' => ['nullable', 'string', 'max:32'],
            'medido_en' => ['nullable', 'date'],
            'lectura_uuid' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mensajes(): array
    {
        return [
            'distancia_cm.required_without_all' => 'Debes enviar distancia_cm (o error si la medición falló).',
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return self::reglas();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::mensajes();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->lectura_uuid)) {
            $limpio = trim($this->lectura_uuid);
            $this->merge(['lectura_uuid' => $limpio === '' ? null : $limpio]);
        }
    }
}
