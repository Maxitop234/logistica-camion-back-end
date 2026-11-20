<?php

namespace App\Http\Resources;

use App\Models\LecturaPunto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LecturaPunto
 */
class LecturaPuntoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lectura_uuid' => $this->lectura_uuid,
            'origen' => $this->origen,
            'distancia_cm' => $this->distancia_cm !== null ? (float) $this->distancia_cm : null,
            'porcentaje_llenado' => (int) $this->porcentaje_llenado,
            'estado_llenado' => $this->estado_llenado,
            'bateria_voltaje' => $this->bateria_voltaje !== null ? (float) $this->bateria_voltaje : null,
            'medido_en' => ($this->medido_en ?? $this->created_at)?->toIso8601String(),
        ];
    }
}
