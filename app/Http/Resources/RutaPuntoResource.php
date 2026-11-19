<?php

namespace App\Http\Resources;

use App\Models\RutaPunto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RutaPunto
 */
class RutaPuntoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orden_parada' => $this->orden_parada,
            'estado_llenado_inicial' => $this->estado_llenado_inicial,
            'estado_parada' => $this->estado_parada,
            'distancia_tramo_km' => $this->distancia_tramo_km !== null ? (float) $this->distancia_tramo_km : null,
            'duracion_tramo_min' => $this->duracion_tramo_min,
            'hora_estimada_llegada' => $this->hora_estimada_llegada?->toIso8601String(),
            'hora_real_recoleccion' => $this->hora_real_recoleccion?->toIso8601String(),
            'observaciones' => $this->observaciones,
            'punto' => $this->whenLoaded('punto', fn (): array => [
                'id' => $this->punto->id,
                'nombre' => $this->punto->nombre,
                'direccion' => $this->punto->direccion,
                'tipo_punto' => $this->punto->tipo_punto,
                'latitud' => (float) $this->punto->latitud,
                'longitud' => (float) $this->punto->longitud,
                'estado_llenado' => $this->punto->estado_llenado,
                'porcentaje_llenado' => $this->punto->porcentaje_llenado,
            ]),
        ];
    }
}
