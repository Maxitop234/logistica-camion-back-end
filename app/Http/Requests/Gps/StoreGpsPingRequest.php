<?php

namespace App\Http\Requests\Gps;

use Illuminate\Foundation\Http\FormRequest;

class StoreGpsPingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->has('pings')) {
            return [
                'pings' => ['required', 'array', 'min:1', 'max:200'],
                'pings.*.ping_uuid' => ['required', 'uuid'],
                'pings.*.latitud' => ['required', 'numeric', 'between:-90,90'],
                'pings.*.longitud' => ['required', 'numeric', 'between:-180,180'],
                'pings.*.velocidad_kmh' => ['nullable', 'numeric', 'min:0', 'max:300'],
                'pings.*.precision_m' => ['nullable', 'numeric', 'min:0', 'max:5000'],
                'pings.*.capturado_en' => ['required', 'date'],
                'pings.*.ruta_id' => ['nullable', 'integer', 'min:1'],
                'pings.*.simulado' => ['nullable', 'boolean'],
            ];
        }

        return [
            'ping_uuid' => ['required', 'uuid'],
            'latitud' => ['required', 'numeric', 'between:-90,90'],
            'longitud' => ['required', 'numeric', 'between:-180,180'],
            'velocidad_kmh' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'precision_m' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            'capturado_en' => ['required', 'date'],
            'ruta_id' => ['nullable', 'integer', 'min:1'],
            'simulado' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Normaliza tanto un ping individual como un lote `pings[]` a una lista.
     *
     * @return list<array<string, mixed>>
     */
    public function pingsNormalizados(): array
    {
        $validated = $this->validated();
        if (isset($validated['pings']) && is_array($validated['pings'])) {
            return array_values($validated['pings']);
        }

        return [$validated];
    }
}
