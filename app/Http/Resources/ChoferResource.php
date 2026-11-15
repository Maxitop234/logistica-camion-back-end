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
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'email' => $this->whenLoaded('user', fn () => $this->user->email),
            'is_active' => $this->whenLoaded('user', fn () => $this->user->is_active),
            'nombre' => $this->nombre_cifrado,
            'telefono' => $this->telefono_cifrado,
            'licencia_clase' => $this->licencia_clase,
            'disponible' => $this->disponible,
            'camion_actual' => $this->whenLoaded('camionActual', fn () => $this->camionActual ? [
                'id' => $this->camionActual->id,
                'patente' => $this->camionActual->patente,
            ] : null),
        ];
    }
}
