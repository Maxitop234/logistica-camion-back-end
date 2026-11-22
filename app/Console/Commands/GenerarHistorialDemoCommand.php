<?php

namespace App\Console\Commands;

use App\Models\Administrador;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\LecturaPunto;
use App\Models\ProyeccionFlota;
use App\Models\Punto;
use App\Models\Ruta;
use App\Models\RutaPunto;
use App\Models\TelemetriaGps;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

class GenerarHistorialDemoCommand extends Command
{
    protected $signature = 'ecoruta:generar-historial
        {--meses=3 : Meses de historial hacia atrás terminando hoy (1 a 12)}
        {--semilla=20261121 : Semilla entera fija para generación reproducible}';

    protected $description = 'Genera historial coherente y reproducible para el ambiente de demostración (solo APP_DEMO_MODE=true).';

    /** @var array<int, string> */
    private const PUNTOS_CRONICOS = ['Feria Pinto', 'Portal Temuco'];

    /** @var array<int, string> */
    private const PUNTOS_SUBDIMENSIONADOS = ['Feria Pinto', 'Portal Temuco', 'Mercado Municipal de Temuco'];

    /** @var array<int, string> */
    private const PUNTOS_SOBREDIMENSIONADOS = ['Sector Santa Rosa', 'Sector Pedro de Valdivia', 'Pulmahue Padre Las Casas'];

    /** @var array<int, string> */
    private const SENSORES_SIN_REPORTE = ['Sector Pueblo Nuevo', 'Sector Santa Rosa'];

    /** @var array<int, string> */
    private const SENSORES_BATERIA_BAJA = ['Parque Estadio Germán Becker', 'Municipalidad de Padre Las Casas'];

    public function handle(): int
    {
        if (! config('app.demo_mode')) {
            $this->error(
                'El comando ecoruta:generar-historial está bloqueado porque APP_DEMO_MODE=false. '
                .'Solo puede ejecutarse en el ambiente de demostración.'
            );

            return self::FAILURE;
        }

        $meses = max(1, min(12, (int) $this->option('meses')));
        $semilla = (int) $this->option('semilla');

        $choferes = Chofer::query()->with('user')->orderBy('id')->get();
        $camiones = Camion::query()->where('estado_operativo', '!=', 'inactivo')->orderBy('id')->get();
        $puntos = Punto::query()->where('activo', true)->orderBy('id')->get();
        $admin = Administrador::query()->orderBy('id')->first();

        if ($choferes->isEmpty() || $camiones->isEmpty() || $puntos->isEmpty()) {
            $this->error(
                'Faltan choferes, camiones o puntos en la base de datos. '
                .'Ejecuta primero: php artisan db:seed --class=DemoSeeder'
            );

            return self::FAILURE;
        }

        $ahora = Carbon::now();
        $fechaFin = $ahora->copy()->startOfDay();
        $fechaInicio = $fechaFin->copy()->subMonths($meses)->startOfDay();
        $rng = new Randomizer(new Mt19937($semilla));

        $resumen = DB::transaction(function () use (
            $ahora,
            $fechaInicio,
            $fechaFin,
            $semilla,
            $rng,
            $choferes,
            $camiones,
            $puntos,
            $admin,
        ): array {
            // Idempotencia: limpiar historial previo antes de regenerar en limpio.
            TelemetriaGps::query()->delete();
            RutaPunto::query()->delete();
            Ruta::query()->delete();
            LecturaPunto::query()->delete();
            ProyeccionFlota::query()->delete();

            // Ajustar alta de camiones al inicio del período para reflejar días arrendados completos.
            $altaFlota = $fechaInicio->copy()->subDays(5);
            foreach ($camiones as $camion) {
                $camion->forceFill([
                    'created_at' => $altaFlota,
                    'updated_at' => $ahora,
                ])->saveQuietly();
            }

            $rutasCreadas = 0;
            $paradasCreadas = 0;
            $pingsBuffer = [];
            $lecturasBuffer = [];
            $proyeccionesBuffer = [];
            $totalPings = 0;
            $totalLecturas = 0;
            $plantaLat = (float) config('services.planta.latitud', -38.7655);
            $plantaLng = (float) config('services.planta.longitud', -72.5865);

            // Clasificar camiones: titulares (los 2 primeros) y respaldo arrendado (si existe 3ro).
            $camionNelson = $camiones->firstWhere('chofer_actual_id', $choferes[0]->id) ?? $camiones[0];
            $camionMaxi = $choferes->count() > 1
                ? ($camiones->firstWhere('chofer_actual_id', $choferes[1]->id) ?? $camiones->get(1, $camionNelson))
                : $camionNelson;
            $camionReserva = $camiones->first(
                static fn (Camion $c): bool => $c->id !== $camionNelson->id && $c->id !== $camionMaxi->id
            );

            // Grupos de sectores para variar recorridos y tiempos por sector.
            // Cada turno recorre dos sectores reales consecutivos (por nombre), en ciclo.
            $puntosPorSector = $puntos->groupBy(static fn (Punto $p): string => $p->sectorResuelto());
            $nombresSector = $puntosPorSector->keys()->sort()->values()->all();
            $sectoresCiclo = [];
            foreach ($nombresSector as $indice => $nombreSector) {
                $siguienteSector = $nombresSector[($indice + 1) % count($nombresSector)];
                $sectoresCiclo[] = array_values(array_unique([$nombreSector, $siguienteSector]));
            }
            if ($sectoresCiclo === []) {
                $sectoresCiclo = [[Punto::SIN_SECTOR]];
            }

            $totalDias = (int) $fechaInicio->diffInDays($fechaFin);
            $secuencia = 0;

            for ($offset = 0; $offset <= $totalDias; $offset++) {
                $dia = $fechaInicio->copy()->addDays($offset);
                $diaSemana = $dia->dayOfWeekIso; // 1=Lun .. 7=Dom

                // Domingo sin rutas operativas (permite acumular llenado y reflejar ociosidad real).
                if ($diaSemana === 7) {
                    continue;
                }

                // Lunes y viernes tienen mayor carga; sábado es media jornada.
                $esDiaAlto = in_array($diaSemana, [1, 5], true);
                $esSabado = $diaSemana === 6;

                // Definir qué choferes y camiones salen hoy (repartido entre Nelson y Maximiliano;
                // camión reserva sale solo ~1 día de cada 3 días de alta carga).
                $turnos = [];
                if ($esSabado) {
                    $idxChofer = ($offset % $choferes->count());
                    $choferTurno = $choferes[$idxChofer];
                    $camionTurno = $idxChofer === 0 ? $camionNelson : $camionMaxi;
                    $turnos[] = [$choferTurno, $camionTurno, $sectoresCiclo[$offset % count($sectoresCiclo)]];
                } else {
                    foreach ($choferes as $idx => $chofer) {
                        $usaReserva = $camionReserva instanceof Camion
                            && $idx === 0
                            && $esDiaAlto
                            && ($offset % 7 === 0);
                        $camionBase = $idx === 0 ? $camionNelson : $camionMaxi;
                        $camionTurno = $usaReserva ? $camionReserva : $camionBase;
                        $sectoresTurno = $sectoresCiclo[($offset + $idx * 2) % count($sectoresCiclo)];
                        $turnos[] = [$chofer, $camionTurno, $sectoresTurno];
                    }
                }

                $puntosListosDelDia = 0;
                $camionesDelDia = count($turnos);

                foreach ($turnos as $turnoIdx => [$chofer, $camion, $sectoresTurno]) {
                    $candidatos = collect();
                    foreach ($sectoresTurno as $sec) {
                        $candidatos = $candidatos->merge($puntosPorSector->get($sec, collect()));
                    }
                    // Evitar visitar puntos sobredimensionados casi siempre para mantenerlos bajo 40 %.
                    $candidatos = $candidatos
                        ->reject(static fn (Punto $p): bool => in_array($p->nombre, self::PUNTOS_SOBREDIMENSIONADOS, true))
                        ->values();

                    if ($candidatos->isEmpty()) {
                        continue;
                    }

                    $cantidadParadas = min($candidatos->count(), $esDiaAlto ? 5 : ($esSabado ? 3 : 4));
                    $paradasPuntos = $candidatos->take($cantidadParadas)->values();
                    $puntosListosDelDia += $paradasPuntos->count();

                    // Hora de inicio realista (08:00–08:30); si es hoy y aún es temprano, ajustar hacia atrás.
                    $horaInicio = $dia->copy()->setTime(8, $turnoIdx * 20 + $rng->getInt(0, 15));
                    if ($horaInicio->greaterThan($ahora->copy()->subHours(3))) {
                        $horaInicio = $ahora->copy()->subHours(4)->subMinutes($turnoIdx * 15);
                    }

                    // Calcular distancia planificada aproximada desde la planta y entre paradas.
                    $distEstimada = 0.0;
                    $prevLat = $plantaLat;
                    $prevLng = $plantaLng;
                    $tramos = [];
                    foreach ($paradasPuntos as $p) {
                        $km = max(1.4, $this->haversineKm($prevLat, $prevLng, (float) $p->latitud, (float) $p->longitud) * 1.28);
                        $tramos[] = round($km, 2);
                        $distEstimada += $km;
                        $prevLat = (float) $p->latitud;
                        $prevLng = (float) $p->longitud;
                    }
                    $distRetorno = max(1.5, $this->haversineKm($prevLat, $prevLng, $plantaLat, $plantaLng) * 1.25);
                    $distEstimada = round($distEstimada + $distRetorno, 2);

                    // Desvío controlado: Maximiliano algo más adherente, Nelson con algún desvío puntual.
                    $factorDesvio = $turnoIdx === 0
                        ? (1.03 + $rng->getInt(0, 11) / 100.0)
                        : (1.01 + $rng->getInt(0, 7) / 100.0);
                    if ($offset % 11 === 0 && $turnoIdx === 0) {
                        $factorDesvio = 1.19; // Desvío notable para KPI 11.
                    }
                    $distReal = round($distEstimada * $factorDesvio, 2);

                    // Duración ajustada por sector (los del centro son más lentos por congestión).
                    $esSectorLento = collect($sectoresTurno)
                        ->contains(static fn (string $sector): bool => str_contains(mb_strtolower($sector), 'centro'));
                    $minPorParada = $esSectorLento ? $rng->getInt(28, 36) : $rng->getInt(20, 27);
                    $duracionEstimadaMin = max(45, $paradasPuntos->count() * 24 + (int) round($distEstimada * 2.1));
                    $duracionRealMin = max(50, $paradasPuntos->count() * $minPorParada + (int) round($distReal * 2.3));
                    $horaTermino = $horaInicio->copy()->addMinutes($duracionRealMin);
                    if ($horaTermino->greaterThan($ahora->copy()->subMinutes(20))) {
                        $horaTermino = $ahora->copy()->subMinutes(20);
                        $horaInicio = $horaTermino->copy()->subMinutes($duracionRealMin);
                    }

                    $totalEvaluados = $puntos->count();
                    $incluidos = $paradasPuntos->count();
                    $excluidos = max(1, $totalEvaluados - $incluidos);

                    $ruta = Ruta::create([
                        'camion_id' => $camion->id,
                        'chofer_id' => $chofer->id,
                        'creado_por_admin_id' => $admin?->id,
                        'fecha_programada' => $dia->format('Y-m-d'),
                        'estado' => Ruta::ESTADO_COMPLETADA,
                        'total_puntos_evaluados' => $totalEvaluados,
                        'puntos_incluidos_count' => $incluidos,
                        'puntos_excluidos_count' => $excluidos,
                        'distancia_estimada_km' => $distEstimada,
                        'distancia_real_km' => $distReal,
                        'duracion_estimada_min' => $duracionEstimadaMin,
                        'hora_inicio_real' => $horaInicio,
                        'hora_termino_real' => $horaTermino,
                    ]);
                    $rutasCreadas++;

                    // Paradas de la ruta + pings GPS a lo largo del recorrido.
                    $cursorEst = $horaInicio->copy();
                    $cursorReal = $horaInicio->copy();
                    $latActual = $plantaLat;
                    $lngActual = $plantaLng;

                    foreach ($paradasPuntos as $pIdx => $punto) {
                        $secuencia++;
                        $kmTramo = $tramos[$pIdx];
                        $minTramoEst = max(8, (int) round($kmTramo * 2.5 + 12));
                        $desvioMin = $esSectorLento ? $rng->getInt(2, 11) : $rng->getInt(-4, 6);
                        // Forzar una ruta reciente con desvío ETA ≥ 12 min para alerta de ETA.
                        if ($offset >= $totalDias - 4 && $turnoIdx === 0) {
                            $desvioMin = 13;
                        }

                        $cursorEst = $cursorEst->copy()->addMinutes($minTramoEst);
                        $cursorReal = $cursorEst->copy()->addMinutes($desvioMin);
                        if ($cursorReal->greaterThan($horaTermino)) {
                            $cursorReal = $horaTermino->copy()->subMinutes(2);
                        }

                        // Parada omitida ocasional (~5 %, y garantizada en los últimos 4 días para alerta).
                        $esOmitida = ($pIdx === $paradasPuntos->count() - 1)
                            && (($offset % 13 === 0 && $turnoIdx === 1) || ($offset === $totalDias - 2 && $turnoIdx === 0));

                        // Porcentaje de llenado al vaciar: ~86 % en rango útil (≥ 70 %), ~14 % visita temprana.
                        $esPrematura = ! $esOmitida && (($offset + $pIdx) % 8 === 0);
                        $pctAlVaciar = $esPrematura
                            ? $rng->getInt(50, 66)
                            : $rng->getInt(74, 97);

                        RutaPunto::create([
                            'ruta_id' => $ruta->id,
                            'punto_id' => $punto->id,
                            'orden_parada' => $pIdx + 1,
                            'estado_llenado_inicial' => Punto::clasificarEstado($pctAlVaciar),
                            'porcentaje_llenado_al_vaciar' => $esOmitida ? null : $pctAlVaciar,
                            'estado_parada' => $esOmitida ? RutaPunto::ESTADO_OMITIDO : RutaPunto::ESTADO_RECOLECTADO,
                            'distancia_tramo_km' => $kmTramo,
                            'duracion_tramo_min' => $minTramoEst,
                            'hora_estimada_llegada' => $cursorEst,
                            'hora_real_recoleccion' => $esOmitida ? null : $cursorReal,
                            'sync_uuid' => $this->uuidDeterminista($semilla, "rp-{$ruta->id}-{$punto->id}-{$secuencia}"),
                            'sincronizado_offline' => false,
                            'observaciones' => $esOmitida ? 'Acceso vehicular bloqueado temporalmente (demo).' : null,
                        ]);
                        $paradasCreadas++;

                        // Generar 3 pings GPS interpolados por tramo (incluyendo algunos excesos > 50 km/h).
                        $pLat = (float) $punto->latitud;
                        $pLng = (float) $punto->longitud;
                        for ($paso = 1; $paso <= 3; $paso++) {
                            $frac = $paso / 3.0;
                            $exceso = ($paso === 2 && (($offset + $pIdx + $turnoIdx) % 9 === 0));
                            $vel = $exceso
                                ? (float) $rng->getInt(55, 67)
                                : (float) $rng->getInt(22, 46);
                            $tPing = $cursorReal->copy()->subMinutes(max(1, (3 - $paso) * 3));

                            $pingsBuffer[] = [
                                'ruta_id' => $ruta->id,
                                'camion_id' => $camion->id,
                                'chofer_id' => $chofer->id,
                                'ping_uuid' => $this->uuidDeterminista($semilla, "gps-{$ruta->id}-{$pIdx}-{$paso}-{$secuencia}"),
                                'latitud' => round($latActual + ($pLat - $latActual) * $frac, 6),
                                'longitud' => round($lngActual + ($pLng - $lngActual) * $frac, 6),
                                'velocidad_kmh' => $vel,
                                'precision_m' => (float) $rng->getInt(6, 14),
                                'es_simulado' => true,
                                'capturado_en' => $tPing,
                                'created_at' => $tPing,
                                'updated_at' => $tPing,
                            ];
                            $totalPings++;
                        }

                        $latActual = $pLat;
                        $lngActual = $pLng;
                    }

                    // Volcar buffer de pings periódicamente para mantener baja memoria.
                    if (count($pingsBuffer) >= 400) {
                        TelemetriaGps::query()->insert($pingsBuffer);
                        $pingsBuffer = [];
                    }
                }

                // Historial evaluado en proyecciones_flota para los últimos 30 días (hasta ayer).
                if ($offset >= max(0, $totalDias - 30) && $offset < $totalDias) {
                    $proyectados = max(4, $puntosListosDelDia + $rng->getInt(-2, 2));
                    $reales = max(4, $puntosListosDelDia);
                    $error = abs($proyectados - $reales);
                    $acierto = round(max(0.0, (1.0 - ($error / max(1, $reales))) * 100.0), 2);
                    $proyeccionesBuffer[] = [
                        'fecha_objetivo' => $dia->format('Y-m-d'),
                        'puntos_proyectados' => $proyectados,
                        'camiones_proyectados' => max(1, $camionesDelDia),
                        'puntos_reales_listos' => $reales,
                        'porcentaje_acierto' => $acierto,
                        'calculada_en' => $dia->copy()->subDay()->setTime(19, 0),
                        'evaluada_en' => $dia->copy()->setTime(20, 0),
                        'created_at' => $dia->copy()->subDay()->setTime(19, 0),
                        'updated_at' => $dia->copy()->setTime(20, 0),
                    ];
                }
            }

            if ($pingsBuffer !== []) {
                TelemetriaGps::query()->insert($pingsBuffer);
            }
            if ($proyeccionesBuffer !== []) {
                ProyeccionFlota::query()->insert($proyeccionesBuffer);
            }

            // Generar lecturas de sensores coherentes por punto relativas a $ahora:
            // - Subdimensionados (velocidad ≥ 100 %/día)
            // - Crónicos (episodios ≥ 90 % que superan 12 h antes de vaciarse, ≥ 4 veces en últimos 30 días)
            // - Sobredimensionados (máximo < 40 % y velocidad < 10 %/día)
            // - Adecuados (episodios llenos vaciados en 4–9 h)
            // - Estado actual y heartbeats frescos relativos a $ahora (con 2 offline > 8 h y 2 batería < 3,40 V).
            foreach ($puntos as $pIndex => $punto) {
                $esCronico = in_array($punto->nombre, self::PUNTOS_CRONICOS, true);
                $esSubdim = in_array($punto->nombre, self::PUNTOS_SUBDIMENSIONADOS, true);
                $esSobredim = in_array($punto->nombre, self::PUNTOS_SOBREDIMENSIONADOS, true);
                $esOffline = in_array($punto->nombre, self::SENSORES_SIN_REPORTE, true);
                $esBateriaBaja = in_array($punto->nombre, self::SENSORES_BATERIA_BAJA, true);
                $tieneSensor = $punto->tieneSensorVinculado();
                $origen = $tieneSensor ? Punto::ORIGEN_SENSOR_IOT : Punto::ORIGEN_MANUAL;
                $altura = (float) ($punto->altura_contenedor_cm ?: 120.0);
                $voltaje = ! $tieneSensor
                    ? null
                    : ($esBateriaBaja ? round(3.26 + ($pIndex % 2) * 0.06, 2) : round(3.76 + ($pIndex % 4) * 0.08, 2));

                $pctActual = 45;
                $ultimoMedido = $ahora->copy()->subMinutes(20 + ($pIndex % 6) * 10);
                if ($esOffline) {
                    $ultimoMedido = $ahora->copy()->subHours(12 + ($pIndex % 5));
                }

                // Generar ciclos cada 5 días a lo largo del rango [fechaInicio, fechaFin].
                for ($diaCiclo = 0; $diaCiclo <= $totalDias - 2; $diaCiclo += 5) {
                    $base = $fechaInicio->copy()->addDays($diaCiclo)->setTime(8, 0);
                    if ($base->greaterThan($ahora->copy()->subHours(26))) {
                        continue;
                    }

                    if ($esSobredim) {
                        // Llenado muy lento (< 10 %/día) y siempre < 40 %.
                        $pares = [
                            [$base, 12],
                            [$base->copy()->addHours(48), 22],
                            [$base->copy()->addHours(96), 31],
                        ];
                    } elseif ($esSubdim) {
                        // Subdimensionado: sube +56 % en 12 h (≈ 112 %/día ≥ 100 %/día).
                        // Los crónicos permanecen llenos 16–20 h (> 12 h) antes de vaciarse.
                        $horasLleno = $esCronico ? $rng->getInt(16, 21) : $rng->getInt(6, 9);
                        $pares = [
                            [$base, 36],
                            [$base->copy()->addHours(12), 94],
                            [$base->copy()->addHours(12 + $horasLleno), 6],
                        ];
                    } else {
                        // Adecuado: sube ~25–35 %/día y se vacía en 5–9 h tras llegar a lleno.
                        $horasLleno = $rng->getInt(5, 9);
                        $pares = [
                            [$base, 32],
                            [$base->copy()->addHours(36), 74],
                            [$base->copy()->addHours(48), 92],
                            [$base->copy()->addHours(48 + $horasLleno), 5],
                        ];
                    }

                    foreach ($pares as $stepIdx => [$ts, $pct]) {
                        if ($ts->greaterThan($ultimoMedido)) {
                            continue;
                        }
                        $distCm = round($altura * (1.0 - ($pct / 100.0)), 2);
                        $lecturasBuffer[] = [
                            'punto_id' => $punto->id,
                            'lectura_uuid' => $this->uuidDeterminista($semilla, "lec-{$punto->id}-{$diaCiclo}-{$stepIdx}"),
                            'origen' => $origen,
                            'distancia_cm' => $tieneSensor ? $distCm : null,
                            'porcentaje_llenado' => $pct,
                            'estado_llenado' => Punto::clasificarEstado($pct),
                            'bateria_voltaje' => $voltaje,
                            'registrada_por_user_id' => null,
                            'medido_en' => $ts,
                            'created_at' => $ts,
                            'updated_at' => $ts,
                        ];
                        $totalLecturas++;
                    }
                }

                // Última lectura reciente relativa a $ahora (coherente con el perfil del punto).
                $pctActual = $esSobredim
                    ? $rng->getInt(18, 34)
                    : ($esCronico ? $rng->getInt(86, 96) : $rng->getInt(48, 88));
                $distActual = round($altura * (1.0 - ($pctActual / 100.0)), 2);

                $lecturasBuffer[] = [
                    'punto_id' => $punto->id,
                    'lectura_uuid' => $this->uuidDeterminista($semilla, "lec-actual-{$punto->id}"),
                    'origen' => $origen,
                    'distancia_cm' => $tieneSensor ? $distActual : null,
                    'porcentaje_llenado' => $pctActual,
                    'estado_llenado' => Punto::clasificarEstado($pctActual),
                    'bateria_voltaje' => $voltaje,
                    'registrada_por_user_id' => null,
                    'medido_en' => $ultimoMedido,
                    'created_at' => $ultimoMedido,
                    'updated_at' => $ultimoMedido,
                ];
                $totalLecturas++;

                $punto->forceFill([
                    'porcentaje_llenado' => $pctActual,
                    'estado_llenado' => Punto::clasificarEstado($pctActual),
                    'ultima_actualizacion_estado' => $ultimoMedido,
                    'origen_actualizacion' => $origen,
                    'bateria_voltaje' => $voltaje,
                    'ultimo_heartbeat_iot' => $tieneSensor ? $ultimoMedido : null,
                ])->saveQuietly();

                if (count($lecturasBuffer) >= 400) {
                    LecturaPunto::query()->insert($lecturasBuffer);
                    $lecturasBuffer = [];
                }
            }

            if ($lecturasBuffer !== []) {
                LecturaPunto::query()->insert($lecturasBuffer);
            }

            // Actualizar última posición GPS de camiones titulares cerca de la planta/Temuco.
            foreach ([$camionNelson, $camionMaxi] as $cIdx => $cam) {
                $cam->forceFill([
                    'ultima_latitud' => round($plantaLat + 0.025 + $cIdx * 0.008, 6),
                    'ultima_longitud' => round($plantaLng - 0.010 - $cIdx * 0.012, 6),
                    'velocidad_actual_kmh' => 0.0,
                    'ultima_actualizacion_gps' => $ahora->copy()->subMinutes(18 + $cIdx * 7),
                ])->saveQuietly();
            }

            return [
                'rutas' => $rutasCreadas,
                'paradas' => $paradasCreadas,
                'pings' => $totalPings,
                'lecturas' => $totalLecturas,
                'proyecciones' => count($proyeccionesBuffer),
            ];
        });

        $this->info(sprintf(
            'Historial de demostración generado (%s → %s, semilla %d): %d rutas, %d paradas, %d pings GPS, %d lecturas y %d proyecciones.',
            $fechaInicio->format('Y-m-d'),
            $fechaFin->format('Y-m-d'),
            $semilla,
            $resumen['rutas'],
            $resumen['paradas'],
            $resumen['pings'],
            $resumen['lecturas'],
            $resumen['proyecciones'],
        ));

        return self::SUCCESS;
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    private function uuidDeterminista(int $semilla, string $clave): string
    {
        $hex = md5("{$semilla}:{$clave}");

        return sprintf(
            '%s-%s-4%s-a%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 13, 3),
            substr($hex, 17, 3),
            substr($hex, 20, 12),
        );
    }
}
