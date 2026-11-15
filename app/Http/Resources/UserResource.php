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
        $chofer = $this->role === 'chofer' ? $this->chofer : null;
        $administrador = $this->role === 'administrador' ? $this->administrador : null;

        return [
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
                ],
                $administrador !== null => [
                    'id' => $administrador->id,
                    'nombre' => $administrador->nombre_completo,
                    'cargo' => $administrador->cargo,
                    'telefono' => $administrador->telefono,
                ],
                default => null,
            },
            $this->mergeWhen($chofer !== null, fn () => [
                'camion' => ($camion = Camion::where('chofer_actual_id', $chofer->id)->first())
                    ? ['id' => $camion->id, 'patente' => $camion->patente, 'estado_operativo' => $camion->estado_operativo]
                    : null,
                'ruta_activa' => null,
            ]),
        ];
    }
}
