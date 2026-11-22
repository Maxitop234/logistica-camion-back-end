<?php

namespace App\Services;

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

        // 3. Salud de sensores IoT (reciente < 8 h y batería ≥ 3,40 V, KPI-08).
        $ahora = now();
        $puntosConSensor = Punto::query()
            ->where('activo', true)
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

        $pesoSeveridad = ['alta' => 0, 'media' => 1, 'baja' => 2];
        usort($alertas, static fn (array $a, array $b): int => ($pesoSeveridad[$a['severidad']] ?? 2) <=> ($pesoSeveridad[$b['severidad']] ?? 2));

        return $alertas;
    }
}
