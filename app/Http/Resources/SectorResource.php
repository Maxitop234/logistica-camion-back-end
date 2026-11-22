<?php

namespace App\Http\Resources;

use App\Models\Sector;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sector
 */
class SectorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'dias_recoleccion' => $this->resource->diasNormalizados(),
            'dias_recoleccion_nombres' => $this->resource->nombresDias(),
            'periodicidad_semanal' => $this->periodicidad_semanal,
            'dias_coinciden_con_periodicidad' => $this->resource->diasCoincidenConPeriodicidad(),
            'puntos_esperados' => $this->puntos_esperados,
            'puntos_count' => $this->whenCounted('puntos'),
            'puntos_activos_count' => $this->whenCounted('puntos_activos'),
            'descripcion' => $this->descripcion,
            'activo' => (bool) $this->activo,
        ];
    }
}
