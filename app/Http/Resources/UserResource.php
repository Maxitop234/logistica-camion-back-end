<?php

namespace App\Http\Resources;

use App\Models\Camion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $chofer = $this->role === 'chofer'
            ? $this->chofer()->with([
                'licenciaVerificadaPor.administrador',
                'ultimaSolicitudBorrado.resueltaPor.administrador',
                'ultimaSolicitudReactivacion.resueltaPor.administrador',
            ])->first()
            : null;
        $administrador = $this->role === 'administrador' ? $this->administrador : null;
        $solicitudReactivacion = $chofer?->solicitudReactivacionCicloActual();

        $datos = [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'perfil' => match (true) {
                $chofer !== null => [
                    'id' => $chofer->id,
                    'nombre' => $chofer->nombre_cifrado,
                    'telefono' => $chofer->telefono_cifrado,
                    'licencia_clase' => $chofer->licencia_clase,
                    'disponible' => $chofer->disponible,
                    'motivo_desactivacion' => $chofer->motivo_desactivacion,
                    'desactivado_en' => $chofer->desactivado_en?->toIso8601String(),
                    'solicitud_reactivacion' => $solicitudReactivacion
                        ? SolicitudReactivacionChoferResource::resumen($solicitudReactivacion)
                        : null,
                    'licencia' => LicenciaChoferResource::resumen($chofer),
                ],
                $administrador !== null => [
                    'id' => $administrador->id,
                    'nombre' => $administrador->nombre_completo,
                    'cargo' => $administrador->cargo,
                    'telefono' => $administrador->telefono,
                ],
                default => null,
            },
        ];

        if ($chofer !== null) {
            $camion = Camion::where('chofer_actual_id', $chofer->id)->first();

            $datos['camion'] = $camion
                ? ['id' => $camion->id, 'patente' => $camion->patente, 'estado_operativo' => $camion->estado_operativo]
                : null;
            $datos['ruta_activa'] = null;
        }

        return $datos;
    }
}
