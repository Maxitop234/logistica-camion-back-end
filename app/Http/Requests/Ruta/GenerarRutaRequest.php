<?php

namespace App\Http\Requests\Ruta;

use Illuminate\Foundation\Http\FormRequest;

class GenerarRutaRequest extends FormRequest
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
            'camion_id' => ['required', 'integer', 'exists:camiones,id'],
            'chofer_id' => ['required', 'integer', 'exists:choferes,id'],
            'fecha_programada' => ['required', 'date_format:Y-m-d'],
            'origen' => ['sometimes', 'array'],
            'origen.latitud' => ['required_with:origen', 'numeric', 'between:-90,90'],
            'origen.longitud' => ['required_with:origen', 'numeric', 'between:-180,180'],
        ];
    }
}
