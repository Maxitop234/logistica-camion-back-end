<?php

namespace App\Http\Resources;

use App\Models\Punto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Punto
 */
class PuntoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'nombre_automatico' => (bool) $this->nombre_automatico,
            'direccion' => $this->direccion,
            'tipo_punto' => $this->tipo_punto === Punto::TIPO_CONDOMINIO
                ? Punto::TIPO_CONDOMINIO
                : Punto::TIPO_CALLE,
            'latitud' => (float) $this->latitud,
            'longitud' => (float) $this->longitud,
            'porcentaje_llenado' => (int) $this->porcentaje_llenado,
            'estado_llenado' => $this->estado_llenado,
            'origen_actualizacion' => $this->origen_actualizacion ?: Punto::ORIGEN_MANUAL,
            'ultima_actualizacion_estado' => $this->ultima_actualizacion_estado?->toIso8601String(),
            'altura_contenedor_cm' => (float) ($this->altura_contenedor_cm ?: 120.0),
            'tiene_sensor' => $this->resource->tieneSensorVinculado(),
            'sensor_codigo' => $this->sensor_codigo,
            'bateria_voltaje' => $this->bateria_voltaje !== null ? (float) $this->bateria_voltaje : null,
            'ultimo_heartbeat_iot' => $this->ultimo_heartbeat_iot?->toIso8601String(),
            'activo' => (bool) $this->activo,
            'lecturas' => $this->whenLoaded(
                'lecturas',
                fn () => LecturaPuntoResource::collection($this->lecturas)->resolve(),
            ),
        ];
    }
}
