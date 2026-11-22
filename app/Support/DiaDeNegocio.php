<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * "Día de negocio": la fecha civil de la operación (por defecto America/Santiago,
 * configurable con `ZONA_HORARIA_OPERACION`). La base y la API siguen en UTC;
 * solo lo que depende de qué día es (hoy, filtros de fecha, KPI diarios,
 * historial) se calcula con esta zona.
 */
final class DiaDeNegocio
{
    public static function zona(): string
    {
        return (string) config('ecoruta.zona_horaria', 'America/Santiago');
    }

    /** Instante actual expresado en la zona de la operación. */
    public static function ahora(): CarbonImmutable
    {
        return CarbonImmutable::now(self::zona());
    }

    /** Hoy a las 00:00 en la zona de la operación. */
    public static function hoy(): CarbonImmutable
    {
        return self::ahora()->startOfDay();
    }

    /** Fecha `Y-m-d` de [$instante] en la zona de la operación. */
    public static function fecha(CarbonInterface $instante): string
    {
        return CarbonImmutable::instance($instante)->setTimezone(self::zona())->format('Y-m-d');
    }

    /** Medianoche (en la zona de la operación) de la fecha `Y-m-d` de [$fecha], sin importar su zona. */
    public static function inicioDelDia(CarbonInterface|string $fecha): CarbonImmutable
    {
        $ymd = is_string($fecha) ? substr($fecha, 0, 10) : $fecha->format('Y-m-d');

        return CarbonImmutable::parse($ymd, self::zona())->startOfDay();
    }

    /** 23:59:59 (en la zona de la operación) de la fecha `Y-m-d` de [$fecha]. */
    public static function finDelDia(CarbonInterface|string $fecha): CarbonImmutable
    {
        return self::inicioDelDia($fecha)->endOfDay();
    }
}
