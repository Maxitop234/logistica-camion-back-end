<?php

namespace App\Services\Puntos;

use App\Enums\OrigenEstado;
use App\Models\Punto;
use App\Models\Sector;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Calcula hasta cuándo es vigente el estado de llenado de un punto (ADR-0013).
 *
 * | Origen        | Vigente hasta                                                        |
 * |---------------|----------------------------------------------------------------------|
 * | `sensor_iot`  | `ESTADO_SENSOR_VIGENCIA_HORAS` después de la lectura                 |
 * | `manual`      | Fin del siguiente día de recolección del sector, inclusive           |
 * | `chofer`      | Igual que `manual` (nivel observado en terreno)                      |
 * | `recoleccion` | Fin de la víspera del siguiente día de recolección del sector        |
 *
 * "Siguiente día de recolección" es el primero estrictamente posterior a la
 * fecha local del evento (zona `ecoruta.zona_horaria`). Sin sector o sin días
 * de recolección, el estado manual, observado o de recolección vale solo
 * hasta el fin del día local del evento.
 */
class VigenciaDeEstado
{
    public function calcularVigenteHasta(Punto $punto): ?Carbon
    {
        if ($punto->estado_llenado === null || $punto->ultima_actualizacion_estado === null) {
            return null;
        }

        return Carbon::instance($this->limiteDeVigencia($punto, CarbonImmutable::instance($punto->ultima_actualizacion_estado)));
    }

    public function estaVigente(Punto $punto, ?CarbonInterface $ahora = null): bool
    {
        if ($punto->estado_llenado === null || $punto->estado_vigente_hasta === null) {
            return false;
        }

        return ($ahora ?? now())->lessThanOrEqualTo($punto->estado_vigente_hasta);
    }

    /**
     * Recalcula la vigencia de los puntos con estado de un sector (p. ej. al
     * cambiar sus días de recolección).
     */
    public function recalcularSector(Sector $sector): int
    {
        $actualizados = 0;

        Punto::query()
            ->where('sector_id', $sector->id)
            ->whereNotNull('estado_llenado')
            ->each(function (Punto $punto) use ($sector, &$actualizados): void {
                $punto->setRelation('sector', $sector);
                $punto->estado_vigente_hasta = $this->calcularVigenteHasta($punto);
                if ($punto->isDirty('estado_vigente_hasta')) {
                    $punto->saveQuietly();
                    $actualizados++;
                }
            });

        return $actualizados;
    }

    /**
     * Instante (UTC) hasta el que vale un estado registrado en `$momento`.
     */
    private function limiteDeVigencia(Punto $punto, CarbonImmutable $momento): CarbonImmutable
    {
        $origen = OrigenEstado::tryFrom((string) $punto->origen_actualizacion) ?? OrigenEstado::Manual;

        if ($origen === OrigenEstado::SensorIot) {
            return $momento->addHours(max(1, (int) config('ecoruta.estado.vigencia_sensor_horas', 24)))->utc();
        }

        $momentoLocal = $momento->setTimezone((string) config('ecoruta.zona_horaria', 'America/Santiago'));
        $siguiente = $this->sectorDe($punto)?->siguienteDiaDeRecoleccion($momentoLocal);

        if ($siguiente === null) {
            return $momentoLocal->endOfDay()->utc();
        }

        return match ($origen) {
            OrigenEstado::Recoleccion => $siguiente->subDay()->endOfDay()->utc(),
            default => $siguiente->endOfDay()->utc(),
        };
    }

    private function sectorDe(Punto $punto): ?Sector
    {
        if ($punto->sector_id === null) {
            return null;
        }

        if ($punto->relationLoaded('sector') && $punto->sector instanceof Sector && $punto->sector->id === $punto->sector_id) {
            return $punto->sector;
        }

        return Sector::find($punto->sector_id);
    }
}
