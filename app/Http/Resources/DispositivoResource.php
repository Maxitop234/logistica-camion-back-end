<?php

namespace App\Http\Resources;

use App\Models\Dispositivo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Dispositivo
 */
class DispositivoResource extends JsonResource
{
    /**
     * Nunca incluye el token ni su hash: el sensor lo retira solo.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $punto = $this->punto;

        return [
            'id' => $this->id,
            'codigo_hardware' => $this->codigo_hardware,
            'estado' => $this->estado,
            'estado_conexion' => $this->resource->estadoConexion(),
            'punto' => $punto === null ? null : [
                'id' => $punto->id,
                'nombre' => $punto->nombre,
                'direccion' => $punto->direccion,
                'porcentaje_llenado' => $punto->porcentaje_llenado,
                'estado_llenado' => $punto->estado_llenado,
            ],
            'tipo_sensor' => $this->tipo_sensor,
            'conectividad' => $this->conectividad,
            'firmware_version' => $this->firmware_version,
            'altura_contenedor_cm' => (float) $this->altura_contenedor_cm,
            'distancia_lleno_cm' => (float) $this->distancia_lleno_cm,
            'zona_ciega_cm' => $this->resource->zonaCiegaCm(),
            'intervalo_lectura_seg' => $this->intervalo_lectura_seg,
            'bateria_voltaje' => $this->bateria_voltaje !== null ? (float) $this->bateria_voltaje : null,
            'bateria_porcentaje' => $this->resource->bateriaPorcentaje(),
            'bateria_baja' => $this->resource->bateriaBaja(),
            'rssi_dbm' => $this->rssi_dbm,
            'ultima_conexion_en' => $this->ultima_conexion_en?->toIso8601String(),
            'ultima_lectura_en' => $this->ultima_lectura_en?->toIso8601String(),
            'ultima_distancia_cm' => $this->ultima_distancia_cm !== null ? (float) $this->ultima_distancia_cm : null,
            'minutos_sin_reportar' => $this->resource->minutosSinReportar(),
            'lecturas_invalidas_seguidas' => $this->lecturas_invalidas_seguidas,
            'token_por_retirar' => $this->token_pendiente_cifrado !== null,
            'anunciado_en' => $this->anunciado_en?->toIso8601String(),
            'vinculado_en' => $this->vinculado_en?->toIso8601String(),
            'es_simulado' => (bool) $this->es_simulado,
            'notas' => $this->notas,
        ];
    }
}
