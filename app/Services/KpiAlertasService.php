<?php

namespace App\Services;

use App\Models\Dispositivo;
use App\Models\Punto;
use App\Models\Ruta;
use App\Models\RutaPunto;
use Carbon\CarbonInterface;

class KpiAlertasService
{
    public function __construct(
        private readonly KpiCalculatorService $calculator,
    ) {}

    /**
     * Genera alertas accionables y recomendaciones en el backend para el período
     * solicitado (desbordes > 12 h, puntos crónicos, paradas omitidas, desvíos
     * ETA, sensores sin reporte ≥ 8 h y batería < 3,40 V). Cada alerta trae
     * `gravedad`, un número comparable dentro de su tipo (veces superado, paradas
     * omitidas, minutos de desvío, horas sin reporte o volts bajo el mínimo).
     *
     * @return list<array<string, mixed>>
     */
    public function calcular(CarbonInterface $inicio, CarbonInterface $fin): array
    {
        $inicioDia = $inicio->toMutable()->startOfDay();
        $finDia = $fin->toMutable()->endOfDay();
        $alertas = [];

        $dashboard = $this->calculator->calcularDashboard($inicioDia, $finDia);
        $kpis = collect($dashboard['kpis'])->keyBy('id');

        // 1. Puntos crónicos / desbordes > 12 h (KPI-05).
        $kpi05 = $kpis->get('KPI-05');
        foreach ($kpi05['extras']['puntos_cronicos'] ?? [] as $cronico) {
            $enSector = $cronico['sector'] === Punto::SIN_SECTOR ? '' : " en el sector {$cronico['sector']}";
            $alertas[] = [
                'id' => "desborde-cronico-{$cronico['punto_id']}-{$finDia->format('Y-m-d')}",
                'tipo' => 'desborde',
                'severidad' => 'alta',
                'titulo' => "Punto crónico de desborde: {$cronico['nombre']}",
                'detalle' => "Superó {$cronico['superaron_12h']} veces el límite de 12 h lleno{$enSector} (máx. {$cronico['max_horas']} h).",
                'recomendacion' => 'Aumentar frecuencia de recolección o evaluar ampliación de capacidad en este sector.',
                'kpi' => 'KPI-05',
                'gravedad' => (float) $cronico['superaron_12h'],
            ];
        }

        // 2. Paradas omitidas y desvíos ETA en rutas del período (KPI-07).
        $rutas = Ruta::query()
            ->whereBetween('fecha_programada', [$inicioDia->format('Y-m-d'), $finDia->format('Y-m-d')])
            ->whereIn('estado', [Ruta::ESTADO_EN_CURSO, Ruta::ESTADO_COMPLETADA])
            ->with(['paradas.punto'])
            ->get();

        foreach ($rutas as $ruta) {
            $omitidas = $ruta->paradas->filter(static fn (RutaPunto $p): bool => $p->estado_parada === RutaPunto::ESTADO_OMITIDO || ($ruta->estado === Ruta::ESTADO_COMPLETADA && $p->estado_parada === RutaPunto::ESTADO_PENDIENTE));
            if ($omitidas->isNotEmpty()) {
                $nombres = $omitidas->map(static fn (RutaPunto $p): string => $p->punto?->nombre ?? "Punto #{$p->punto_id}")->take(3)->implode(', ');
                $alertas[] = [
                    'id' => "omitidas-ruta-{$ruta->id}",
                    'tipo' => 'parada_omitida',
                    'severidad' => 'alta',
                    'titulo' => "{$omitidas->count()} parada(s) sin recolectar en ruta #{$ruta->id}",
                    'detalle' => "Quedaron pendientes: {$nombres}.",
                    'recomendacion' => 'Priorizar estos puntos en la próxima generación de rutas.',
                    'kpi' => 'KPI-07',
                    'gravedad' => (float) $omitidas->count(),
                ];
            }

            $desvios = [];
            foreach ($ruta->paradas as $p) {
                if ($p->hora_estimada_llegada && $p->hora_real_recoleccion) {
                    $desvios[] = $p->hora_estimada_llegada->diffInMinutes($p->hora_real_recoleccion, false);
                }
            }
            if ($desvios !== []) {
                $prom = round(array_sum($desvios) / count($desvios), 1);
                if (abs($prom) >= 8.0) {
                    $tarde = $prom > 0;
                    $absMin = number_format(abs($prom), 1, ',', '.');
                    $alertas[] = [
                        'id' => "eta-ruta-{$ruta->id}",
                        'tipo' => 'desviacion_eta',
                        'severidad' => abs($prom) >= 15.0 ? 'alta' : 'media',
                        'titulo' => $tarde
                            ? "Llegadas tarde en ruta #{$ruta->id} (+{$absMin} min)"
                            : "Llegadas adelantadas en ruta #{$ruta->id} (-{$absMin} min)",
                        'detalle' => "Desviación promedio de {$absMin} min respecto a la hora estimada por OSRM.",
                        'recomendacion' => $tarde
                            ? 'Ajustar holgura de tiempos estimados para las próximas rutas de ese sector.'
                            : 'Revisar tiempos de parada para evitar sobrestimar la jornada.',
                        'kpi' => 'KPI-07',
                        'gravedad' => abs($prom),
                    ];
                }
            }
        }

        // 3. Salud de sensores IoT (dispositivos vinculados; KPI-08).
        $alertas = [...$alertas, ...$this->alertasDeDispositivos(), ...$this->alertasDePuntosSinDispositivo()];

        $pesoSeveridad = ['alta' => 0, 'media' => 1, 'baja' => 2];
        usort($alertas, static fn (array $a, array $b): int => ($pesoSeveridad[$a['severidad']] ?? 2) <=> ($pesoSeveridad[$b['severidad']] ?? 2));

        return $alertas;
    }

    /**
     * Sensores vinculados: sin reportar más de N intervalos, batería baja, lecturas
     * inválidas repetidas y sensores nuevos esperando aprobación.
     *
     * @return list<array<string, mixed>>
     */
    private function alertasDeDispositivos(): array
    {
        $alertas = [];
        $umbralInvalidas = max(1, (int) config('iot.invalidas_para_alerta', 3));

        $dispositivos = Dispositivo::query()->with('punto')->where('estado', '!=', Dispositivo::ESTADO_DESACTIVADO)->orderBy('id')->get();
        foreach ($dispositivos as $dispositivo) {
            $nombre = $dispositivo->punto instanceof Punto ? $dispositivo->punto->nombre : $dispositivo->codigo_hardware;

            if ($dispositivo->estado === Dispositivo::ESTADO_PENDIENTE) {
                $alertas[] = [
                    'id' => "sensor-pendiente-{$dispositivo->id}",
                    'tipo' => 'sensor_pendiente',
                    'severidad' => 'baja',
                    'titulo' => "Sensor nuevo por vincular: {$dispositivo->codigo_hardware}",
                    'detalle' => 'Se anunció y espera que un administrador lo apruebe y elija su punto.',
                    'recomendacion' => 'Ingresa el código de la etiqueta en Sensores para vincularlo.',
                    'kpi' => 'KPI-08',
                    'gravedad' => 1.0,
                ];

                continue;
            }

            if ($dispositivo->sinReportar()) {
                $minutos = $dispositivo->minutosSinReportar();
                $horas = $minutos === null ? null : round($minutos / 60, 1);
                $limite = max(1, (int) config('iot.intervalos_sin_reporte', 2));
                $alertas[] = [
                    'id' => 'sensor-sin-reporte-'.($dispositivo->punto_id ?? "d{$dispositivo->id}"),
                    'tipo' => 'sensor_sin_reporte',
                    'severidad' => 'alta',
                    'titulo' => "Sensor de {$nombre} sin reportar",
                    'detalle' => ($horas === null
                        ? 'El sensor vinculado aún no envía su primera lectura'
                        : 'No envía lecturas hace '.number_format($horas, 1, ',', '.').' h')
                        ." (más de {$limite} intervalos de ".intdiv($dispositivo->intervalo_lectura_seg, 60).' min).',
                    'recomendacion' => 'Revisar conectividad o alimentación del ESP32 en terreno.',
                    'kpi' => 'KPI-08',
                    'gravedad' => $horas ?? 9999.0,
                ];
            }

            if ($dispositivo->bateriaBaja()) {
                $volt = number_format((float) $dispositivo->bateria_voltaje, 2, ',', '.');
                $minimo = (float) config('iot.bateria_minima_v', 3.40);
                $alertas[] = [
                    'id' => 'bateria-baja-'.($dispositivo->punto_id ?? "d{$dispositivo->id}"),
                    'tipo' => 'bateria_baja',
                    'severidad' => 'media',
                    'titulo' => "Batería baja en {$nombre} ({$volt} V)",
                    'detalle' => 'Está bajo el mínimo recomendado de '.number_format($minimo, 2, ',', '.').' V.',
                    'recomendacion' => 'Programar cambio o recarga de batería en la próxima visita.',
                    'kpi' => 'KPI-08',
                    'gravedad' => round($minimo - (float) $dispositivo->bateria_voltaje, 2),
                ];
            }

            if ($dispositivo->lecturas_invalidas_seguidas >= $umbralInvalidas) {
                $alertas[] = [
                    'id' => "lecturas-invalidas-{$dispositivo->id}",
                    'tipo' => 'lecturas_invalidas',
                    'severidad' => 'media',
                    'titulo' => "Lecturas inválidas repetidas en {$nombre}",
                    'detalle' => "El sensor envió {$dispositivo->lecturas_invalidas_seguidas} lecturas imposibles seguidas (sin eco, zona ciega o fuera de rango).",
                    'recomendacion' => 'Revisar la orientación del sensor, obstáculos delante o humedad en el módulo; recalibrar si cambió el contenedor.',
                    'kpi' => 'KPI-08',
                    'gravedad' => (float) $dispositivo->lecturas_invalidas_seguidas,
                ];
            }
        }

        return $alertas;
    }

    /**
     * Puntos con sensor registrado antes de los dispositivos (umbral fijo de 8 h).
     *
     * @return list<array<string, mixed>>
     */
    private function alertasDePuntosSinDispositivo(): array
    {
        $alertas = [];
        $ahora = now();
        $puntosConSensor = Punto::query()
            ->where('activo', true)
            ->whereDoesntHave('dispositivo')
            ->where(static function ($q): void {
                $q->whereNotNull('sensor_codigo')->orWhereNotNull('sensor_token_hash');
            })
            ->get();

        foreach ($puntosConSensor as $punto) {
            $horasSinReporte = $punto->ultimo_heartbeat_iot instanceof CarbonInterface
                ? round($punto->ultimo_heartbeat_iot->diffInMinutes($ahora, false) / 60.0, 1)
                : null;

            if ($horasSinReporte === null || $horasSinReporte >= KpiCalculatorService::MAX_HORAS_SENSOR_RECIENTE) {
                $detalle = $horasSinReporte === null
                    ? 'El dispositivo vinculado aún no envía su primera lectura.'
                    : 'No envía lecturas hace '.number_format($horasSinReporte, 1, ',', '.').' h (umbral máximo 8 h).';
                $alertas[] = [
                    'id' => "sensor-sin-reporte-{$punto->id}",
                    'tipo' => 'sensor_sin_reporte',
                    'severidad' => 'alta',
                    'titulo' => "Sensor de {$punto->nombre} sin reportar",
                    'detalle' => $detalle,
                    'recomendacion' => 'Revisar conectividad o alimentación del ESP32 en terreno.',
                    'kpi' => 'KPI-08',
                    'gravedad' => $horasSinReporte ?? 9999.0,
                ];
            }

            if ($punto->bateria_voltaje !== null && (float) $punto->bateria_voltaje < KpiCalculatorService::VOLTAJE_MINIMO_BATERIA) {
                $volt = number_format((float) $punto->bateria_voltaje, 2, ',', '.');
                $alertas[] = [
                    'id' => "bateria-baja-{$punto->id}",
                    'tipo' => 'bateria_baja',
                    'severidad' => 'media',
                    'titulo' => "Batería baja en {$punto->nombre} ({$volt} V)",
                    'detalle' => 'Está bajo el mínimo recomendado de 3,40 V.',
                    'recomendacion' => 'Programar cambio o recarga de batería en la próxima visita.',
                    'kpi' => 'KPI-08',
                    'gravedad' => round(KpiCalculatorService::VOLTAJE_MINIMO_BATERIA - (float) $punto->bateria_voltaje, 2),
                ];
            }
        }

        return $alertas;
    }
}
