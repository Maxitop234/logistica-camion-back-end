<?php

namespace App\Enums;

/**
 * Origen del último estado de llenado de un punto (`puntos.origen_actualizacion`).
 *
 * Define cuánto tiempo el estado se considera vigente (ADR-0013):
 * - `SensorIot`: lectura de un sensor; vigente unas horas.
 * - `Manual`: ajuste del administrador; vigente hasta el siguiente día de recolección, inclusive.
 * - `Chofer`: nivel observado por el chofer en terreno; igual que `Manual`.
 * - `Recoleccion`: el punto se vació; vigente hasta la víspera del siguiente día de recolección.
 */
enum OrigenEstado: string
{
    case Manual = 'manual';
    case Chofer = 'chofer';
    case SensorIot = 'sensor_iot';
    case Recoleccion = 'recoleccion';
}
