<?php

namespace App\Http\Resources;

use App\Models\Chofer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Chofer
 */
class ChoferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $solicitudActual = $this->resource->solicitudReactivacionCicloActual();

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'email' => $this->whenLoaded('user', fn () => $this->user->email),
            'is_active' => $this->whenLoaded('user', fn () => $this->user->is_active),
            'debe_cambiar_password' => $this->whenLoaded('user', fn () => $this->user->debe_cambiar_password),
            'nombre' => $this->nombre_cifrado,
            'telefono' => $this->telefono_cifrado,
            'licencia_clase' => $this->licencia_clase,
            'disponible' => $this->disponible,
            'motivo_desactivacion' => $this->motivo_desactivacion,
            'desactivado_en' => $this->desactivado_en?->toIso8601String(),
            'solicitud_reactivacion' => $solicitudActual
                ? SolicitudReactivacionChoferResource::resumen($solicitudActual)
                : null,
            'camion_actual' => $this->whenLoaded('camionActual', fn () => $this->camionActual ? [
                'id' => $this->camionActual->id,
                'patente' => $this->camionActual->patente,
            ] : null),
            'licencia' => LicenciaChoferResource::resumen($this->resource),
            'avatar' => $this->resource->avatarResuelto(),
        ];
    }
}
