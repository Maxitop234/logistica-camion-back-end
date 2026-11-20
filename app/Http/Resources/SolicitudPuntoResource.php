<?php

namespace App\Http\Resources;

use App\Models\SolicitudPunto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SolicitudPunto
 */
class SolicitudPuntoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $chofer = $this->relationLoaded('chofer') ? $this->chofer : null;
        $resueltaPor = $this->relationLoaded('resueltaPor') ? $this->resueltaPor : null;

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'direccion' => $this->direccion,
            'tipo_punto' => $this->tipo_punto,
            'latitud' => (float) $this->latitud,
            'longitud' => (float) $this->longitud,
            'observacion' => $this->observacion,
            'estado' => $this->estado,
            'punto_creado_id' => $this->punto_creado_id,
            'motivo_rechazo' => $this->motivo_rechazo,
            'created_at' => $this->created_at?->toIso8601String(),
            'resuelta_en' => $this->resuelta_en?->toIso8601String(),
            'resuelta_por' => LicenciaChoferResource::datosUsuario($resueltaPor),
            'chofer' => $chofer ? [
                'id' => $chofer->id,
                'nombre' => $chofer->nombre_cifrado,
                'email' => $chofer->relationLoaded('user') ? $chofer->user->email : null,
            ] : null,
        ];
    }
}
