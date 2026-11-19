<?php

namespace App\Http\Resources;

use App\Models\SolicitudReactivacionChofer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SolicitudReactivacionChofer
 */
class SolicitudReactivacionChoferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public static function resumen(SolicitudReactivacionChofer $solicitud): array
    {
        $resueltaPor = $solicitud->relationLoaded('resueltaPor')
            ? $solicitud->resueltaPor
            : null;

        return [
            'id' => $solicitud->id,
            'ciclo_desactivacion' => $solicitud->ciclo_desactivacion,
            'motivo_desactivacion' => $solicitud->motivo_desactivacion,
            'motivo' => $solicitud->motivo,
            'estado' => $solicitud->estado,
            'created_at' => $solicitud->created_at?->toIso8601String(),
            'resuelta_en' => $solicitud->resuelta_en?->toIso8601String(),
            'motivo_rechazo' => $solicitud->motivo_rechazo,
            'resuelta_por' => LicenciaChoferResource::datosUsuario($resueltaPor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...self::resumen($this->resource),
            'chofer' => $this->whenLoaded('chofer', function (): array {
                $chofer = $this->chofer;

                return [
                    'id' => $chofer->id,
                    'nombre' => $chofer->nombre_cifrado,
                    'email' => $chofer->relationLoaded('user') ? $chofer->user?->email : null,
                    'licencia_clase' => $chofer->licencia_clase,
                    'is_active' => $chofer->relationLoaded('user') ? (bool) $chofer->user?->is_active : null,
                ];
            }),
        ];
    }
}
