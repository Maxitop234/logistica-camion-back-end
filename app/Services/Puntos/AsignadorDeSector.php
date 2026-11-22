<?php

namespace App\Services\Puntos;

use App\Models\Punto;
use App\Models\Sector;

/**
 * Sugiere el sector de un punto nuevo cuando no se indicó: el del punto activo
 * con sector más cercano. El punto queda marcado para revisión (FE-05).
 */
class AsignadorDeSector
{
    public function sectorMasCercano(float $latitud, float $longitud): ?Sector
    {
        $factorLongitud = cos(deg2rad($latitud));

        $sectorId = Punto::query()
            ->withoutEagerLoads()
            ->where('activo', true)
            ->whereNotNull('sector_id')
            ->whereHas('sector', fn ($query) => $query->where('activo', true))
            ->orderByRaw(
                '((latitud - ?) * (latitud - ?)) + ((longitud - ?) * ?) * ((longitud - ?) * ?)',
                [$latitud, $latitud, $longitud, $factorLongitud, $longitud, $factorLongitud],
            )
            ->value('sector_id');

        return $sectorId !== null ? Sector::query()->whereKey($sectorId)->first() : null;
    }
}
