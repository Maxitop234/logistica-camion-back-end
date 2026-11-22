<?php

namespace App\Http\Requests\Dispositivo;

use App\Concerns\ZonaDeOperacionValidationRules;
use App\Models\Punto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AprobarDispositivoRequest extends FormRequest
{
    use ZonaDeOperacionValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Se indica `punto_id` (punto existente) o `punto` (datos para crearlo ahí mismo).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'codigo_hardware' => ['required', 'string', 'max:64'],
            'codigo_vinculacion' => ['required', 'string', 'max:32'],
            'punto_id' => ['required_without:punto', 'nullable', 'integer', 'exists:puntos,id'],
            'punto' => ['required_without:punto_id', 'nullable', 'array'],
            'punto.nombre' => ['required_with:punto', 'string', 'min:3', 'max:255'],
            'punto.direccion' => ['required_with:punto', 'string', 'min:3', 'max:255'],
            'punto.tipo_punto' => ['required_with:punto', Rule::in(Punto::TIPOS_VALIDOS)],
            'punto.sector_id' => ['nullable', 'integer', Rule::exists('sectores', 'id')->where('activo', true)],
            'punto.latitud' => ['required_with:punto', 'numeric', 'between:'.config('ecoruta.zona.latitud_min').','.config('ecoruta.zona.latitud_max')],
            'punto.longitud' => ['required_with:punto', 'numeric', 'between:'.config('ecoruta.zona.longitud_min').','.config('ecoruta.zona.longitud_max')],
        ];
    }
}
