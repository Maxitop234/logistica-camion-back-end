<?php

namespace App\Http\Resources;

use App\Models\SolicitudBorradoLicencia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SolicitudBorradoLicencia
 */
class SolicitudBorradoLicenciaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...LicenciaChoferResource::resumenSolicitud($this->resource),
            'chofer' => $this->whenLoaded('chofer', function (): array {
                $chofer = $this->chofer;

                return [
                    'id' => $chofer->id,
                    'nombre' => $chofer->nombre_cifrado,
                    'email' => $chofer->relationLoaded('user') ? $chofer->user?->email : null,
                    'licencia_clase' => $chofer->licencia_clase,
                    'licencia_estado' => $chofer->licencia_estado,
                    'tiene_archivo' => $chofer->tieneArchivoLicencia(),
                    'borrado_habilitado' => (bool) $chofer->licencia_borrado_habilitado,
                ];
            }),
        ];
    }
}
