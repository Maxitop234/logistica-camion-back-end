<?php

namespace App\Http\Resources;

use App\Models\Ruta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ruta
 */
class RutaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha_programada' => $this->fecha_programada->format('Y-m-d'),
            'estado' => $this->estado,
            'camion' => $this->whenLoaded('camion', fn (): ?array => $this->camion ? [
                'id' => $this->camion->id,
                'patente' => $this->camion->patente,
                'estado_operativo' => $this->camion->estado_operativo,
            ] : null),
            'chofer' => $this->whenLoaded('chofer', fn (): ?array => $this->chofer ? [
                'id' => $this->chofer->id,
                'nombre' => $this->chofer->nombre_cifrado,
            ] : null),
            'total_puntos_evaluados' => $this->total_puntos_evaluados,
            'puntos_incluidos_count' => $this->puntos_incluidos_count,
            'puntos_excluidos_count' => $this->puntos_excluidos_count,
            'distancia_estimada_km' => (float) $this->distancia_estimada_km,
            'duracion_estimada_min' => $this->duracion_estimada_min,
            'encoded_polyline' => $this->encoded_polyline,
            'hora_inicio_real' => $this->hora_inicio_real?->toIso8601String(),
            'hora_termino_real' => $this->hora_termino_real?->toIso8601String(),
            'paradas' => RutaPuntoResource::collection($this->whenLoaded('paradas')),
        ];
    }
}
