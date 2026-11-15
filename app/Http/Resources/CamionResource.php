<?php

namespace App\Http\Resources;

use App\Models\Camion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Camion
 */
class CamionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patente' => $this->patente,
            'marca' => $this->marca,
            'modelo' => $this->modelo,
            'anio' => $this->anio,
            'capacidad_kg' => $this->capacidad_kg,
            'estado_operativo' => $this->estado_operativo,
            'chofer_actual' => $this->whenLoaded('choferActual', fn () => $this->choferActual ? [
                'id' => $this->choferActual->id,
                'nombre' => $this->choferActual->nombre_cifrado,
            ] : null),
            'ultima_posicion' => [
                'latitud' => $this->ultima_latitud,
                'longitud' => $this->ultima_longitud,
                'velocidad_kmh' => $this->velocidad_actual_kmh,
                'actualizada_en' => $this->ultima_actualizacion_gps,
            ],
        ];
    }
}
