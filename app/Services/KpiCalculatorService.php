<?php

namespace App\Services;

use App\Models\Camion;
use App\Models\LecturaPunto;
use App\Models\ParametroOperativo;
use App\Models\ProyeccionFlota;
use App\Models\Punto;
use App\Models\Ruta;
use App\Models\RutaPunto;
use App\Models\TelemetriaGps;
use App\Support\DiaDeNegocio;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class KpiCalculatorService
{
    public const LIMITE_HORAS_DESBORDE = 12.0;

    public const MAX_HORAS_SENSOR_RECIENTE = 8.0;

    public const VOLTAJE_MINIMO_BATERIA = 3.40;

    public const PENALIZACION_EXCESO_VELOCIDAD = 0.15;

    public const MENSAJE_SIN_DATOS = 'Aún no hay datos suficientes para este período';

    /**
     * Calcula el tablero completo de los 11 KPI de negocio agrupados en 4
     * categorías, además de los parámetros operativos vigentes (indicando cuáles
     * siguen como valor de referencia).
     *
     * @return array<string, mixed>
     */
    public function calcularDashboard(CarbonInterface $inicio, CarbonInterface $fin, string $agrupacion = 'semana'): array
    {
        $inicioDia = Carbon::instance($inicio)->startOfDay();
        $finDia = Carbon::instance($fin)->endOfDay();
        $agrupacionValida = in_array($agrupacion, ['dia', 'semana', 'mes'], true) ? $agrupacion : 'semana';

        $parametros = ParametroOperativo::catalogoResuelto();
        $diasPeriodo = max(1, (int) $inicioDia->copy()->startOfDay()->diffInDays($finDia->copy()->startOfDay()) + 1);
        $prevInicio = $inicioDia->copy()->subDays($diasPeriodo);
        $prevFin = $inicioDia->copy()->subSecond();

        $actual = $this->cargarContextoPeriodo($inicioDia, $finDia);
        $previo = $this->cargarContextoPeriodo($prevInicio, $prevFin);

        $kpis = [
            $this->kpi01CostoOperativoPorPunto($actual, $previo, $parametros, $agrupacionValida),
            $this->kpi02AhorroRutaFija($actual, $previo, $parametros, $agrupacionValida),
            $this->kpi03UsoFlotaArrendada($actual, $previo, $parametros, $agrupacionValida),
            $this->kpi04EfectividadVisitas($actual, $previo, $parametros, $agrupacionValida),
            $this->kpi05RiesgoDesborde($actual, $previo, $agrupacionValida),
            $this->kpi06DimensionamientoContenedores($actual),
            $this->kpi07CumplimientoPlanificacion($actual, $previo, $agrupacionValida),
            $this->kpi08ConfiabilidadSensores($actual),
            $this->kpi09FlotaNecesariaManana($parametros),
            $this->kpi10IndiceDesempenoChofer($actual),
            $this->kpi11CostoExtraConduccion($actual, $previo, $parametros, $agrupacionValida),
        ];

        $hayReferencias = collect($parametros)->contains(
            static fn (array $p): bool => (bool) $p['es_referencia']
        );

        return [
            'periodo' => [
                'fecha_inicio' => $inicioDia->format('Y-m-d'),
                'fecha_fin' => $finDia->format('Y-m-d'),
                'agrupacion' => $agrupacionValida,
                'dias' => $diasPeriodo,
            ],
            'parametros' => array_values($parametros),
            'tiene_valores_referencia' => $hayReferencias,
            'categorias' => [
                ['id' => 'costos', 'titulo' => 'Costos'],
                ['id' => 'nivel_servicio', 'titulo' => 'Nivel de servicio'],
                ['id' => 'operacion', 'titulo' => 'Operación'],
                ['id' => 'choferes', 'titulo' => 'Choferes'],
            ],
            'kpis' => $kpis,
        ];
    }

    /**
     * Días del período en los que el camión perteneció a la flota arrendada
     * (acotado por fecha de creación y baja, independientemente de si salió a ruta).
     */
    public function diasArrendadosEnPeriodo(Camion $camion, Carbon $inicioDia, Carbon $finDia): int
    {
        if ($camion->estado_operativo === 'inactivo' && ! $camion->desactivado_en instanceof CarbonInterface) {
            return 0;
        }

        $inicioPeriodo = $inicioDia->copy()->startOfDay();
        $finPeriodo = $finDia->copy()->startOfDay();

        $desde = $camion->created_at instanceof CarbonInterface
            ? $camion->created_at->toMutable()->startOfDay()
            : $inicioPeriodo->copy();
        if ($desde->lessThan($inicioPeriodo)) {
            $desde = $inicioPeriodo->copy();
        }

        $hasta = $finPeriodo->copy();
        if ($camion->desactivado_en instanceof CarbonInterface) {
            $baja = $camion->desactivado_en->toMutable()->startOfDay();
            if ($baja->lessThan($hasta)) {
                $hasta = $baja;
            }
        }

        if ($hasta->lessThan($desde)) {
            return 0;
        }

        return (int) $desde->diffInDays($hasta) + 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function cargarContextoPeriodo(Carbon $inicioDia, Carbon $finDia): array
    {
        $camiones = Camion::query()->with('choferActual')->get();

        $rutas = Ruta::query()
            ->whereBetween('fecha_programada', [$inicioDia->format('Y-m-d'), $finDia->format('Y-m-d')])
            ->whereIn('estado', [Ruta::ESTADO_EN_CURSO, Ruta::ESTADO_COMPLETADA])
            ->with(['camion', 'chofer', 'paradas.punto'])
            ->orderBy('fecha_programada')
            ->get();

        $rutaIds = $rutas->pluck('id')->all();

        $pingsPorRuta = $rutaIds === []
            ? collect()
            : TelemetriaGps::query()
                ->whereIn('ruta_id', $rutaIds)
                ->get()
                ->groupBy('ruta_id');

        $puntos = Punto::query()->get();

        $lecturas = LecturaPunto::query()
            ->whereBetween('medido_en', [$inicioDia->copy()->subDays(3), $finDia])
            ->orderBy('medido_en')
            ->orderBy('id')
            ->get()
            ->groupBy('punto_id');

        $diasArrendadosPorCamion = [];
        foreach ($camiones as $camion) {
            $dias = $this->diasArrendadosEnPeriodo($camion, $inicioDia, $finDia);
            if ($dias > 0) {
                $diasArrendadosPorCamion[$camion->id] = $dias;
            }
        }

        return [
            'inicio' => $inicioDia,
            'fin' => $finDia,
            'camiones' => $camiones,
            'dias_arrendados' => $diasArrendadosPorCamion,
            'rutas' => $rutas,
            'pings_por_ruta' => $pingsPorRuta,
            'puntos' => $puntos,
            'lecturas' => $lecturas,
        ];
    }

    /**
     * KPI 1: Costo operativo por punto vaciado.
     * Fórmula: (km × precio_combustible / rendimiento + arriendo_diario × días_arrendados) / puntos_vaciados.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $prev
     * @param  array<string, array<string, mixed>>  $params
     * @return array<string, mixed>
     */
    private function kpi01CostoOperativoPorPunto(array $ctx, array $prev, array $params, string $agrupacion): array
    {
        $calc = $this->calcularCostosOperativos($ctx, $params);
        $calcPrev = $this->calcularCostosOperativos($prev, $params);

        $formula = '((km recorridos × precio combustible ÷ rendimiento) + (arriendo diario × días arrendados)) ÷ puntos vaciados';

        if ($calc['puntos_vaciados'] === 0 || $calc['costo_total'] <= 0) {
            return $this->kpiSinDatos(
                id: 'KPI-01',
                categoria: 'costos',
                titulo: 'Costo operativo por punto vaciado',
                meta: '≤ $12.000 / punto',
                metaNumerica: 12000,
                unidad: '$/punto',
                comoSeCalcula: $formula,
                vistas: ['linea', 'barras', 'tabla'],
            );
        }

        $valor = round($calc['costo_total'] / $calc['puntos_vaciados'], 0);
        $valorPrev = ($calcPrev['puntos_vaciados'] > 0 && $calcPrev['costo_total'] > 0)
            ? round($calcPrev['costo_total'] / $calcPrev['puntos_vaciados'], 0)
            : null;
        $tendencia = $this->calcularTendencia($valor, $valorPrev);

        $semaforo = $valor <= 12000 ? 'cumple' : ($valor <= 15000 ? 'cerca' : 'no_cumple');
        $interpretacion = sprintf(
            'Cada punto vaciado costó %s en el período (%d puntos, %s en combustible y %s en arriendo de flota)%s.',
            $this->formatoPesos($valor),
            $calc['puntos_vaciados'],
            $this->formatoPesos($calc['costo_combustible']),
            $this->formatoPesos($calc['costo_arriendo']),
            $this->textoTendencia($tendencia, menorEsMejor: true),
        );

        // Serie de tendencia por cubo de período (combustible de cada cubo + arriendo proporcional).
        $seriesLinea = $this->serieCostoPorPunto($ctx, $params, $agrupacion);

        // Comparación por camión (barras) y tabla con camiones y sectores.
        $barras = [];
        $filasTabla = [];
        foreach ($calc['por_camion'] as $item) {
            $costoPunto = $item['puntos'] > 0 ? round($item['costo_total'] / $item['puntos'], 0) : $item['costo_total'];
            if ($item['puntos'] > 0) {
                $barras[] = [
                    'etiqueta' => $item['patente'],
                    'valor' => $costoPunto,
                    'unidad' => '$/punto',
                    'detalle' => "{$item['puntos']} pts · {$item['dias_arrendados']} días arrendados",
                ];
            }
            $filasTabla[] = [
                $item['patente'],
                (string) $item['dias_arrendados'],
                number_format($item['km'], 1, ',', '.').' km',
                (string) $item['puntos'],
                $this->formatoPesos($item['costo_combustible']),
                $this->formatoPesos($item['costo_arriendo']),
                $item['puntos'] > 0 ? $this->formatoPesos($costoPunto) : 'Sin vaciados',
            ];
        }
        usort($barras, static fn (array $a, array $b): int => $b['valor'] <=> $a['valor']);

        return [
            'id' => 'KPI-01',
            'categoria' => 'costos',
            'titulo' => 'Costo operativo por punto vaciado',
            'es_proyeccion' => false,
            'sin_datos_suficientes' => false,
            'mensaje_sin_datos' => self::MENSAJE_SIN_DATOS,
            'valor' => $valor,
            'unidad' => '$/punto',
            'valor_formateado' => $this->formatoPesos($valor),
            'meta' => '≤ $12.000 / punto',
            'meta_numerica' => 12000.0,
            'semaforo' => $semaforo,
            'tendencia_pct' => $tendencia,
            'tendencia_positiva' => $tendencia === null ? null : $tendencia <= 0,
            'interpretacion' => $interpretacion,
            'como_se_calcula' => $formula,
            'vistas_disponibles' => ['linea', 'barras', 'tabla'],
            'series_linea' => $seriesLinea,
            'comparacion_barras' => $barras,
            'tabla' => [
                'columnas' => ['Camión', 'Días arrend.', 'Km', 'Puntos', 'Combustible', 'Arriendo', '$/punto'],
                'filas' => $filasTabla,
            ],
            'metricas_usadas' => [
                [
                    'nombre' => 'Km recorridos',
                    'valor' => number_format($calc['km_totales'], 1, ',', '.').' km',
                    'valor_numerico' => round($calc['km_totales'], 2),
                    'unidad' => 'km',
                    'es_referencia' => false,
                    'serie' => $this->serieKmPorCubo($ctx['rutas'], $agrupacion),
                ],
                [
                    'nombre' => 'Costo combustible',
                    'valor' => $this->formatoPesos($calc['costo_combustible']),
                    'valor_numerico' => round($calc['costo_combustible'], 0),
                    'unidad' => '$',
                    'es_referencia' => (bool) $params[ParametroOperativo::PRECIO_COMBUSTIBLE_LITRO]['es_referencia'],
                    'serie' => array_map(static fn (array $c): array => [
                        'etiqueta' => $c['patente'],
                        'valor' => round($c['costo_combustible'], 0),
                    ], $calc['por_camion']),
                ],
                [
                    'nombre' => 'Costo de arriendo (días en flota)',
                    'valor' => $this->formatoPesos($calc['costo_arriendo']),
                    'valor_numerico' => round($calc['costo_arriendo'], 0),
                    'unidad' => '$',
                    'es_referencia' => $calc['uso_arriendo_referencia'],
                    'serie' => array_map(static fn (array $c): array => [
                        'etiqueta' => $c['patente'],
                        'valor' => round($c['costo_arriendo'], 0),
                    ], $calc['por_camion']),
                ],
                [
                    'nombre' => 'Puntos vaciados',
                    'valor' => (string) $calc['puntos_vaciados'],
                    'valor_numerico' => (float) $calc['puntos_vaciados'],
                    'unidad' => 'puntos',
                    'es_referencia' => false,
                    'serie' => $this->seriePuntosVaciadosPorCubo($ctx['rutas'], $agrupacion),
                ],
            ],
            'extras' => [
                'por_sector' => $calc['por_sector'],
                'costo_combustible' => round($calc['costo_combustible'], 0),
                'costo_arriendo' => round($calc['costo_arriendo'], 0),
                'dias_arrendados_totales' => $calc['dias_arrendados_totales'],
            ],
        ];
    }

    /**
     * KPI 2: Ahorro frente a la ruta fija.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $prev
     * @param  array<string, array<string, mixed>>  $params
     * @return array<string, mixed>
     */
    private function kpi02AhorroRutaFija(array $ctx, array $prev, array $params, string $agrupacion): array
    {
        $calc = $this->calcularAhorro($ctx, $params);
        $calcPrev = $this->calcularAhorro($prev, $params);

        $formula = 'Puntos excluidos por no estar listos × km promedio por parada × costo por km (y × tiempo promedio por parada para horas ahorradas)';

        if ($calc['evaluados'] === 0 || $calc['incluidos'] === 0) {
            return $this->kpiSinDatos(
                id: 'KPI-02',
                categoria: 'costos',
                titulo: 'Ahorro frente a la ruta fija',
                meta: '≥ 25 % de visitas evitadas',
                metaNumerica: 25.0,
                unidad: '$',
                comoSeCalcula: $formula,
                vistas: ['linea', 'barras', 'tabla'],
            );
        }

        $valor = $calc['pesos_ahorrados'];
        $valorPrev = ($calcPrev['evaluados'] > 0 && $calcPrev['incluidos'] > 0) ? $calcPrev['pesos_ahorrados'] : null;
        $tendencia = $this->calcularTendencia($valor, $valorPrev);
        $pctExcluidos = $calc['porcentaje_excluidos'];
        $semaforo = $pctExcluidos >= 25.0 ? 'cumple' : ($pctExcluidos >= 15.0 ? 'cerca' : 'no_cumple');

        $interpretacion = sprintf(
            'Se evitaron %d visitas innecesarias (%.1f %% del total), ahorrando %s km, %s h y %s%s.',
            $calc['excluidos'],
            $pctExcluidos,
            number_format($calc['km_ahorrados'], 1, ',', '.'),
            number_format($calc['horas_ahorradas'], 1, ',', '.'),
            $this->formatoPesos($valor),
            $this->textoTendencia($tendencia, menorEsMejor: false),
        );

        return [
            'id' => 'KPI-02',
            'categoria' => 'costos',
            'titulo' => 'Ahorro frente a la ruta fija',
            'es_proyeccion' => false,
            'sin_datos_suficientes' => false,
            'mensaje_sin_datos' => self::MENSAJE_SIN_DATOS,
            'valor' => $valor,
            'unidad' => '$',
            'valor_formateado' => $this->formatoPesos($valor),
            'meta' => '≥ 25 % de visitas evitadas',
            'meta_numerica' => 25.0,
            'semaforo' => $semaforo,
            'tendencia_pct' => $tendencia,
            'tendencia_positiva' => $tendencia === null ? null : $tendencia >= 0,
            'interpretacion' => $interpretacion,
            'como_se_calcula' => $formula,
            'vistas_disponibles' => ['linea', 'barras', 'tabla'],
            'series_linea' => $this->serieAhorroPorCubo($ctx, $params, $agrupacion),
            'comparacion_barras' => [
                ['etiqueta' => 'Pesos ahorrados ($)', 'valor' => $calc['pesos_ahorrados'], 'unidad' => '$', 'detalle' => null],
                ['etiqueta' => 'Km ahorrados', 'valor' => $calc['km_ahorrados'], 'unidad' => 'km', 'detalle' => null],
                ['etiqueta' => 'Horas ahorradas', 'valor' => $calc['horas_ahorradas'], 'unidad' => 'h', 'detalle' => null],
            ],
            'tabla' => [
                'columnas' => ['Concepto', 'Valor'],
                'filas' => [
                    ['Puntos evaluados', (string) $calc['evaluados']],
                    ['Puntos excluidos (no listos)', "{$calc['excluidos']} ({$pctExcluidos} %)"],
                    ['Km promedio por parada', number_format($calc['km_por_parada'], 2, ',', '.').' km'],
                    ['Km ahorrados', number_format($calc['km_ahorrados'], 1, ',', '.').' km'],
                    ['Horas ahorradas', number_format($calc['horas_ahorradas'], 1, ',', '.').' h'],
                    ['Costo promedio por km', $this->formatoPesos($calc['costo_por_km'])],
                    ['Ahorro total estimado', $this->formatoPesos($calc['pesos_ahorrados'])],
                ],
            ],
            'metricas_usadas' => [
                [
                    'nombre' => 'Puntos excluidos',
                    'valor' => "{$calc['excluidos']} de {$calc['evaluados']} ({$pctExcluidos} %)",
                    'valor_numerico' => (float) $calc['excluidos'],
                    'unidad' => 'puntos',
                    'es_referencia' => false,
                    'serie' => $this->serieExcluidosPorCubo($ctx['rutas'], $agrupacion),
                ],
                [
                    'nombre' => 'Km ahorrados',
                    'valor' => number_format($calc['km_ahorrados'], 1, ',', '.').' km',
                    'valor_numerico' => $calc['km_ahorrados'],
                    'unidad' => 'km',
                    'es_referencia' => false,
                    'serie' => [],
                ],
                [
                    'nombre' => 'Horas ahorradas',
                    'valor' => number_format($calc['horas_ahorradas'], 1, ',', '.').' h',
                    'valor_numerico' => $calc['horas_ahorradas'],
                    'unidad' => 'h',
                    'es_referencia' => false,
                    'serie' => [],
                ],
                [
                    'nombre' => 'Costo por km',
                    'valor' => $this->formatoPesos($calc['costo_por_km']).'/km',
                    'valor_numerico' => $calc['costo_por_km'],
                    'unidad' => '$/km',
                    'es_referencia' => (bool) $params[ParametroOperativo::PRECIO_COMBUSTIBLE_LITRO]['es_referencia'],
                    'serie' => [],
                ],
            ],
            'extras' => [
                'km_ahorrados' => $calc['km_ahorrados'],
                'horas_ahorradas' => $calc['horas_ahorradas'],
                'pesos_ahorrados' => $calc['pesos_ahorrados'],
                'porcentaje_excluidos' => $pctExcluidos,
            ],
        ];
    }

    /**
     * KPI 3: Uso de la flota arrendada (cuenta TODOS los días arrendados en el
     * período, hayan salido o no a ruta).
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $prev
     * @param  array<string, array<string, mixed>>  $params
     * @return array<string, mixed>
     */
    private function kpi03UsoFlotaArrendada(array $ctx, array $prev, array $params, string $agrupacion): array
    {
        $calc = $this->calcularUsoFlota($ctx, $params);
        $calcPrev = $this->calcularUsoFlota($prev, $params);

        $formula = '(Horas en ruta ÷ (días arrendados en el período × horas de jornada)) × 100';

        if ($calc['horas_disponibles'] <= 0 || $calc['rutas_totales'] === 0) {
            return $this->kpiSinDatos(
                id: 'KPI-03',
                categoria: 'costos',
                titulo: 'Uso de la flota arrendada',
                meta: '≥ 75 % de horas arrendadas',
                metaNumerica: 75.0,
                unidad: '%',
                comoSeCalcula: $formula,
                vistas: ['linea', 'barras', 'tabla'],
            );
        }

        $valor = $calc['uso_pct'];
        $valorPrev = ($calcPrev['horas_disponibles'] > 0 && $calcPrev['rutas_totales'] > 0) ? $calcPrev['uso_pct'] : null;
        $tendencia = $this->calcularTendencia($valor, $valorPrev);
        $semaforo = $valor >= 75.0 ? 'cumple' : ($valor >= 55.0 ? 'cerca' : 'no_cumple');

        $interpretacion = sprintf(
            'La flota se usó un %s %% del tiempo arrendado (%s h en ruta de %s h pagadas; %d días pagados sin salida)%s.',
            number_format($valor, 1, ',', '.'),
            number_format($calc['horas_en_ruta'], 1, ',', '.'),
            number_format($calc['horas_disponibles'], 1, ',', '.'),
            $calc['dias_sin_salida_totales'],
            $this->textoTendencia($tendencia, menorEsMejor: false),
        );

        $barras = [];
        $filas = [];
        foreach ($calc['por_camion'] as $c) {
            $barras[] = [
                'etiqueta' => $c['patente'],
                'valor' => $c['uso_pct'],
                'unidad' => '%',
                'detalle' => "{$c['dias_con_salida']}/{$c['dias_arrendados']} días · {$c['puntos']} pts",
            ];
            $filas[] = [
                $c['patente'],
                (string) $c['dias_arrendados'],
                (string) $c['dias_con_salida'],
                (string) $c['dias_sin_salida'],
                number_format($c['horas_en_ruta'], 1, ',', '.').' h',
                number_format($c['horas_disponibles'], 1, ',', '.').' h',
                (string) $c['puntos'],
                number_format($c['uso_pct'], 1, ',', '.').' %',
            ];
        }
        usort($barras, static fn (array $a, array $b): int => $b['valor'] <=> $a['valor']);

        return [
            'id' => 'KPI-03',
            'categoria' => 'costos',
            'titulo' => 'Uso de la flota arrendada',
            'es_proyeccion' => false,
            'sin_datos_suficientes' => false,
            'mensaje_sin_datos' => self::MENSAJE_SIN_DATOS,
            'valor' => $valor,
            'unidad' => '%',
            'valor_formateado' => number_format($valor, 1, ',', '.').' %',
            'meta' => '≥ 75 % de horas arrendadas',
            'meta_numerica' => 75.0,
            'semaforo' => $semaforo,
            'tendencia_pct' => $tendencia,
            'tendencia_positiva' => $tendencia === null ? null : $tendencia >= 0,
            'interpretacion' => $interpretacion,
            'como_se_calcula' => $formula,
            'vistas_disponibles' => ['linea', 'barras', 'tabla'],
            'series_linea' => $this->serieHorasRutaPorCubo($ctx['rutas'], $agrupacion),
            'comparacion_barras' => $barras,
            'tabla' => [
                'columnas' => ['Camión', 'Días arrend.', 'Con salida', 'Sin uso', 'Horas ruta', 'Horas pagadas', 'Puntos', 'Uso %'],
                'filas' => $filas,
            ],
            'metricas_usadas' => [
                [
                    'nombre' => 'Horas en ruta',
                    'valor' => number_format($calc['horas_en_ruta'], 1, ',', '.').' h',
                    'valor_numerico' => $calc['horas_en_ruta'],
                    'unidad' => 'h',
                    'es_referencia' => false,
                    'serie' => $this->serieHorasRutaPorCubo($ctx['rutas'], $agrupacion),
                ],
                [
                    'nombre' => 'Horas arrendadas totales',
                    'valor' => number_format($calc['horas_disponibles'], 1, ',', '.').' h',
                    'valor_numerico' => $calc['horas_disponibles'],
                    'unidad' => 'h',
                    'es_referencia' => (bool) $params[ParametroOperativo::HORAS_JORNADA]['es_referencia'],
                    'serie' => array_map(static fn (array $c): array => [
                        'etiqueta' => $c['patente'],
                        'valor' => $c['horas_disponibles'],
                    ], $calc['por_camion']),
                ],
                [
                    'nombre' => 'Días pagados sin salida',
                    'valor' => "{$calc['dias_sin_salida_totales']} días",
                    'valor_numerico' => (float) $calc['dias_sin_salida_totales'],
                    'unidad' => 'días',
                    'es_referencia' => false,
                    'serie' => array_map(static fn (array $c): array => [
                        'etiqueta' => $c['patente'],
                        'valor' => (float) $c['dias_sin_salida'],
                    ], $calc['por_camion']),
                ],
            ],
            'extras' => [
                'dias_arrendados_totales' => $calc['dias_arrendados_totales'],
                'dias_sin_salida_totales' => $calc['dias_sin_salida_totales'],
            ],
        ];
    }

    /**
     * KPI 4: Efectividad de las visitas.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $prev
     * @param  array<string, array<string, mixed>>  $params
     * @return array<string, mixed>
     */
    private function kpi04EfectividadVisitas(array $ctx, array $prev, array $params, string $agrupacion): array
    {
        $umbral = (float) $params[ParametroOperativo::UMBRAL_VISITA_UTIL_PCT]['valor'];
        $calc = $this->calcularEfectividad($ctx['rutas'], $umbral);
        $calcPrev = $this->calcularEfectividad($prev['rutas'], $umbral);

        $formula = "(Paradas vaciadas con llenado ≥ {$umbral} % ÷ total de paradas con lectura al vaciar) × 100";

        if ($calc['total'] === 0) {
            return $this->kpiSinDatos(
                id: 'KPI-04',
                categoria: 'nivel_servicio',
                titulo: 'Efectividad de las visitas',
                meta: '≥ 85 % visitas útiles',
                metaNumerica: 85.0,
                unidad: '%',
                comoSeCalcula: $formula,
                vistas: ['linea', 'barras', 'tabla'],
            );
        }

        $valor = $calc['pct_utiles'];
        $valorPrev = $calcPrev['total'] > 0 ? $calcPrev['pct_utiles'] : null;
        $tendencia = $this->calcularTendencia($valor, $valorPrev);
        $semaforo = $valor >= 85.0 ? 'cumple' : ($valor >= 70.0 ? 'cerca' : 'no_cumple');

        $interpretacion = sprintf(
            '%s %% de las visitas fueron útiles (%d de %d con ≥ %d %% de llenado); se gastaron %s km en visitas prematuras%s.',
            number_format($valor, 1, ',', '.'),
            $calc['utiles'],
            $calc['total'],
            (int) round($umbral),
            number_format($calc['km_prematuras'], 1, ',', '.'),
            $this->textoTendencia($tendencia, menorEsMejor: false),
        );

        $barras = [];
        $filas = [];
        foreach ($calc['por_sector'] as $sector => $s) {
            $pct = round(($s['utiles'] / max(1, $s['total'])) * 100, 1);
            $barras[] = [
                'etiqueta' => $sector,
                'valor' => $pct,
                'unidad' => '%',
                'detalle' => "{$s['utiles']}/{$s['total']} útiles",
            ];
            $filas[] = [
                $sector,
                (string) $s['total'],
                (string) $s['utiles'],
                (string) ($s['total'] - $s['utiles']),
                number_format($s['km_prematuras'], 1, ',', '.').' km',
                number_format($pct, 1, ',', '.').' %',
            ];
        }
        usort($barras, static fn (array $a, array $b): int => $a['valor'] <=> $b['valor']);

        return [
            'id' => 'KPI-04',
            'categoria' => 'nivel_servicio',
            'titulo' => 'Efectividad de las visitas',
            'es_proyeccion' => false,
            'sin_datos_suficientes' => false,
            'mensaje_sin_datos' => self::MENSAJE_SIN_DATOS,
            'valor' => $valor,
            'unidad' => '%',
            'valor_formateado' => number_format($valor, 1, ',', '.').' %',
            'meta' => '≥ 85 % visitas útiles',
            'meta_numerica' => 85.0,
            'semaforo' => $semaforo,
            'tendencia_pct' => $tendencia,
            'tendencia_positiva' => $tendencia === null ? null : $tendencia >= 0,
            'interpretacion' => $interpretacion,
            'como_se_calcula' => $formula,
            'vistas_disponibles' => ['linea', 'barras', 'tabla'],
            'series_linea' => $this->serieEfectividadPorCubo($ctx['rutas'], $umbral, $agrupacion),
            'comparacion_barras' => $barras,
            'tabla' => [
                'columnas' => ['Sector', 'Visitas', 'Útiles', 'Prematuras', 'Km prematuros', 'Efectividad'],
                'filas' => $filas,
            ],
            'metricas_usadas' => [
                [
                    'nombre' => 'Visitas útiles',
                    'valor' => "{$calc['utiles']} de {$calc['total']}",
                    'valor_numerico' => (float) $calc['utiles'],
                    'unidad' => 'visitas',
                    'es_referencia' => (bool) $params[ParametroOperativo::UMBRAL_VISITA_UTIL_PCT]['es_referencia'],
                    'serie' => $barras,
                ],
                [
                    'nombre' => 'Km en visitas prematuras',
                    'valor' => number_format($calc['km_prematuras'], 1, ',', '.').' km',
                    'valor_numerico' => $calc['km_prematuras'],
                    'unidad' => 'km',
                    'es_referencia' => false,
                    'serie' => [],
                ],
                [
                    'nombre' => 'Llenado promedio al vaciar',
                    'valor' => number_format($calc['llenado_promedio'], 1, ',', '.').' %',
                    'valor_numerico' => $calc['llenado_promedio'],
                    'unidad' => '%',
                    'es_referencia' => false,
                    'serie' => [],
                ],
            ],
            'extras' => [
                'km_visitas_prematuras' => $calc['km_prematuras'],
                'umbral_pct' => $umbral,
            ],
        ];
    }

    /**
     * KPI 5: Riesgo de desborde (límite 12 h lleno + lista de puntos crónicos ≥ 3 veces).
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $prev
     * @return array<string, mixed>
     */
    private function kpi05RiesgoDesborde(array $ctx, array $prev, string $agrupacion): array
    {
        $calc = $this->calcularDesbordes($ctx);
        $calcPrev = $this->calcularDesbordes($prev);

        $formula = '(Episodios en estado lleno vaciados en ≤ 12 h ÷ total de episodios en estado lleno) × 100; crónico = superó 12 h 3 o más veces';

        if ($calc['total_episodios'] === 0) {
            return $this->kpiSinDatos(
                id: 'KPI-05',
                categoria: 'nivel_servicio',
                titulo: 'Riesgo de desborde',
                meta: '≥ 90 % vaciados en ≤ 12 h',
                metaNumerica: 90.0,
                unidad: '%',
                comoSeCalcula: $formula,
                vistas: ['linea', 'barras', 'tabla'],
            );
        }

        $valor = $calc['pct_dentro_limite'];
        $valorPrev = $calcPrev['total_episodios'] > 0 ? $calcPrev['pct_dentro_limite'] : null;
        $tendencia = $this->calcularTendencia($valor, $valorPrev);
        $semaforo = ($valor >= 90.0 && $calc['cronicos'] === []) ? 'cumple' : ($valor >= 75.0 ? 'cerca' : 'no_cumple');

        $interpretacion = sprintf(
            '%s %% de los puntos llenos se vaciaron dentro de las 12 h (%d de %d episodios); %d punto(s) crónico(s)%s.',
            number_format($valor, 1, ',', '.'),
            $calc['dentro_limite'],
            $calc['total_episodios'],
            count($calc['cronicos']),
            $this->textoTendencia($tendencia, menorEsMejor: false),
        );

        $barras = [];
        foreach ($calc['por_sector'] as $sector => $s) {
            $pct = round(($s['dentro'] / max(1, $s['total'])) * 100, 1);
            $barras[] = [
                'etiqueta' => $sector,
                'valor' => $pct,
                'unidad' => '%',
                'detalle' => "{$s['fuera']} fuera de límite",
            ];
        }
        usort($barras, static fn (array $a, array $b): int => $a['valor'] <=> $b['valor']);

        $filas = [];
        foreach ($calc['por_punto'] as $p) {
            $filas[] = [
                $p['nombre'],
                $p['sector'],
                $p['tipo'] === Punto::TIPO_CONDOMINIO ? 'Condominio' : 'Calle',
                (string) $p['episodios'],
                (string) $p['superaron_12h'],
                number_format($p['max_horas'], 1, ',', '.').' h',
                $p['es_cronico'] ? 'Crónico' : ($p['superaron_12h'] > 0 ? 'Con retraso' : 'En límite'),
            ];
        }

        return [
            'id' => 'KPI-05',
            'categoria' => 'nivel_servicio',
            'titulo' => 'Riesgo de desborde',
            'es_proyeccion' => false,
            'sin_datos_suficientes' => false,
            'mensaje_sin_datos' => self::MENSAJE_SIN_DATOS,
            'valor' => $valor,
            'unidad' => '%',
            'valor_formateado' => number_format($valor, 1, ',', '.').' %',
            'meta' => '≥ 90 % vaciados en ≤ 12 h',
            'meta_numerica' => 90.0,
            'semaforo' => $semaforo,
            'tendencia_pct' => $tendencia,
            'tendencia_positiva' => $tendencia === null ? null : $tendencia >= 0,
            'interpretacion' => $interpretacion,
            'como_se_calcula' => $formula,
            'vistas_disponibles' => ['linea', 'barras', 'tabla'],
            'series_linea' => $this->serieDesbordesPorCubo($calc['episodios_lista'], $agrupacion),
            'comparacion_barras' => $barras,
            'tabla' => [
                'columnas' => ['Punto', 'Sector', 'Tipo', 'Veces lleno', '> 12 h', 'Máx. espera', 'Estado'],
                'filas' => $filas,
            ],
            'metricas_usadas' => [
                [
                    'nombre' => 'Vaciados en ≤ 12 h',
                    'valor' => "{$calc['dentro_limite']} de {$calc['total_episodios']}",
                    'valor_numerico' => (float) $calc['dentro_limite'],
                    'unidad' => 'episodios',
                    'es_referencia' => false,
                    'serie' => $barras,
                ],
                [
                    'nombre' => 'Puntos crónicos (≥ 3 veces > 12 h)',
                    'valor' => (string) count($calc['cronicos']),
                    'valor_numerico' => (float) count($calc['cronicos']),
                    'unidad' => 'puntos',
                    'es_referencia' => false,
                    'serie' => [],
                ],
                [
                    'nombre' => 'Espera promedio lleno',
                    'valor' => number_format($calc['horas_promedio'], 1, ',', '.').' h',
                    'valor_numerico' => $calc['horas_promedio'],
                    'unidad' => 'h',
                    'es_referencia' => false,
                    'serie' => [],
                ],
            ],
            'extras' => [
                'puntos_cronicos' => $calc['cronicos'],
                'limite_horas' => self::LIMITE_HORAS_DESBORDE,
            ],
        ];
    }

    /**
     * KPI 6: Dimensionamiento de contenedores.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function kpi06DimensionamientoContenedores(array $ctx): array
    {
        $formula = 'Velocidad de llenado (% por día desde lecturas del sensor) + nivel máximo alcanzado: subdimensionado si se llena en < 1 día (≥ 100 %/día), sobredimensionado si nunca supera 40 % y crece < 10 %/día';

        /** @var Collection<int, Punto> $puntos */
        $puntos = $ctx['puntos']->where('activo', true);
        /** @var Collection<int, Collection<int, LecturaPunto>> $lecturasPorPunto */
        $lecturasPorPunto = $ctx['lecturas'];

        $visitasPorPunto = [];
        foreach ($ctx['rutas'] as $ruta) {
            foreach ($ruta->paradas as $parada) {
                if ($parada->estado_parada === RutaPunto::ESTADO_RECOLECTADO) {
                    $visitasPorPunto[$parada->punto_id] = ($visitasPorPunto[$parada->punto_id] ?? 0) + 1;
                }
            }
        }

        $evaluados = [];
        $subdimensionados = [];
        $sobredimensionados = [];
        $adecuados = 0;

        foreach ($puntos as $punto) {
            /** @var Collection<int, LecturaPunto> $lects */
            $lects = ($lecturasPorPunto->get($punto->id) ?? collect())
                ->filter(fn (LecturaPunto $l): bool => $l->medido_en !== null && $l->medido_en->betweenIncluded($ctx['inicio'], $ctx['fin']))
                ->values();

            if ($lects->count() < 2) {
                continue;
            }

            $tasas = [];
            $maxPct = 0;
            $vecesLleno = 0;
            $prev = null;

            foreach ($lects as $l) {
                $maxPct = max($maxPct, (int) $l->porcentaje_llenado);
                if ($l->porcentaje_llenado >= 90) {
                    $vecesLleno++;
                }
                if ($prev instanceof LecturaPunto && $l->medido_en && $prev->medido_en) {
                    $dtHoras = $prev->medido_en->diffInMinutes($l->medido_en, false) / 60.0;
                    $delta = (int) $l->porcentaje_llenado - (int) $prev->porcentaje_llenado;
                    if ($dtHoras >= 1.0 && $delta >= 0) {
                        $tasas[] = $delta / ($dtHoras / 24.0);
                    }
                }
                $prev = $l;
            }

            if ($tasas === []) {
                continue;
            }

            $velDia = round(array_sum($tasas) / count($tasas), 1);
            $visitas = $visitasPorPunto[$punto->id] ?? 0;

            $clasificacion = 'Adecuado';
            if ($velDia >= 100.0) {
                $clasificacion = 'Subdimensionado';
                $subdimensionados[] = [
                    'punto_id' => $punto->id,
                    'nombre' => $punto->nombre,
                    'sector' => $punto->sectorResuelto(),
                    'velocidad_pct_dia' => $velDia,
                    'max_pct' => $maxPct,
                    'visitas' => $visitas,
                ];
            } elseif ($maxPct < 40 && $velDia < 10.0) {
                $clasificacion = 'Sobredimensionado';
                $sobredimensionados[] = [
                    'punto_id' => $punto->id,
                    'nombre' => $punto->nombre,
                    'sector' => $punto->sectorResuelto(),
                    'velocidad_pct_dia' => $velDia,
                    'max_pct' => $maxPct,
                    'visitas' => $visitas,
                ];
            } else {
                $adecuados++;
            }

            $evaluados[] = [
                'nombre' => $punto->nombre,
                'sector' => $punto->sectorResuelto(),
                'velocidad' => $velDia,
                'max_pct' => $maxPct,
                'veces_lleno' => $vecesLleno,
                'visitas' => $visitas,
                'clasificacion' => $clasificacion,
            ];
        }

        if ($evaluados === []) {
            return $this->kpiSinDatos(
                id: 'KPI-06',
                categoria: 'nivel_servicio',
                titulo: 'Dimensionamiento de contenedores',
                meta: '≥ 80 % bien dimensionados',
                metaNumerica: 80.0,
                unidad: '%',
                comoSeCalcula: $formula,
                vistas: ['barras', 'tabla'],
            );
        }

        $valor = round(($adecuados / count($evaluados)) * 100, 1);
        $semaforo = $valor >= 80.0 ? 'cumple' : ($valor >= 65.0 ? 'cerca' : 'no_cumple');

        $interpretacion = sprintf(
            '%s %% de los contenedores evaluados (%d de %d) tienen capacidad adecuada; %d subdimensionado(s) y %d sobredimensionado(s).',
            number_format($valor, 1, ',', '.'),
            $adecuados,
            count($evaluados),
            count($subdimensionados),
            count($sobredimensionados),
        );

        usort($evaluados, static fn (array $a, array $b): int => $b['velocidad'] <=> $a['velocidad']);

        return [
            'id' => 'KPI-06',
            'categoria' => 'nivel_servicio',
            'titulo' => 'Dimensionamiento de contenedores',
            'es_proyeccion' => false,
            'sin_datos_suficientes' => false,
            'mensaje_sin_datos' => self::MENSAJE_SIN_DATOS,
            'valor' => $valor,
            'unidad' => '%',
            'valor_formateado' => number_format($valor, 1, ',', '.').' %',
            'meta' => '≥ 80 % bien dimensionados',
            'meta_numerica' => 80.0,
            'semaforo' => $semaforo,
            'tendencia_pct' => null,
            'tendencia_positiva' => null,
            'interpretacion' => $interpretacion,
            'como_se_calcula' => $formula,
            'vistas_disponibles' => ['barras', 'tabla'],
            'series_linea' => [],
            'comparacion_barras' => array_map(static fn (array $e): array => [
                'etiqueta' => $e['nombre'],
                'valor' => $e['velocidad'],
                'unidad' => '%/día',
                'detalle' => $e['clasificacion'],
            ], array_slice($evaluados, 0, 12)),
            'tabla' => [
                'columnas' => ['Punto', 'Sector', 'Llenado/día', 'Máx. %', 'Veces lleno', 'Visitas', 'Diagnóstico'],
                'filas' => array_map(static fn (array $e): array => [
                    $e['nombre'],
                    $e['sector'],
                    number_format($e['velocidad'], 1, ',', '.').' %/día',
                    "{$e['max_pct']} %",
                    (string) $e['veces_lleno'],
                    (string) $e['visitas'],
                    $e['clasificacion'],
                ], $evaluados),
            ],
            'metricas_usadas' => [
                [
                    'nombre' => 'Contenedores subdimensionados',
                    'valor' => (string) count($subdimensionados),
                    'valor_numerico' => (float) count($subdimensionados),
                    'unidad' => 'puntos',
                    'es_referencia' => false,
                    'serie' => [],
                ],
                [
                    'nombre' => 'Contenedores sobredimensionados',
                    'valor' => (string) count($sobredimensionados),
                    'valor_numerico' => (float) count($sobredimensionados),
                    'unidad' => 'puntos',
                    'es_referencia' => false,
                    'serie' => [],
                ],
                [
                    'nombre' => 'Puntos evaluados con historial',
                    'valor' => (string) count($evaluados),
                    'valor_numerico' => (float) count($evaluados),
                    'unidad' => 'puntos',
                    'es_referencia' => false,
                    'serie' => [],
                ],
            ],
            'extras' => [
                'subdimensionados' => $subdimensionados,
                'sobredimensionados' => $sobredimensionados,
            ],
        ];
    }

    /**
     * KPI 7: Cumplimiento de la planificación (índice compuesto 0–100).
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $prev
     * @return array<string, mixed>
     */
    private function kpi07CumplimientoPlanificacion(array $ctx, array $prev, string $agrupacion): array
    {
        $calc = $this->calcularPlanificacion($ctx['rutas']);
        $calcPrev = $this->calcularPlanificacion($prev['rutas']);

        $formula = 'Índice ponderado: 40 % paradas completadas + 30 % puntualidad ETA (hora real vs estimada) + 30 % adherencia de km reales vs planificados por OSRM';

        if ($calc['paradas_totales'] === 0) {
            return $this->kpiSinDatos(
                id: 'KPI-07',
                categoria: 'operacion',
                titulo: 'Cumplimiento de la planificación',
                meta: '≥ 85 % de confiabilidad',
                metaNumerica: 85.0,
                unidad: '%',
                comoSeCalcula: $formula,
                vistas: ['linea', 'barras', 'tabla'],
            );
        }

        $valor = $calc['indice'];
        $valorPrev = $calcPrev['paradas_totales'] > 0 ? $calcPrev['indice'] : null;
        $tendencia = $this->calcularTendencia($valor, $valorPrev);
        $semaforo = $valor >= 85.0 ? 'cumple' : ($valor >= 70.0 ? 'cerca' : 'no_cumple');

        $interpretacion = sprintf(
            'Confiabilidad de rutas del %s %% (%s %% paradas completadas, desvío ETA promedio %s min y desvío km %s %%)%s.',
            number_format($valor, 1, ',', '.'),
            number_format($calc['paradas_pct'], 1, ',', '.'),
            number_format($calc['desvio_eta_min'], 1, ',', '.'),
            number_format($calc['desvio_km_pct'], 1, ',', '.'),
            $this->textoTendencia($tendencia, menorEsMejor: false),
        );

        return [
            'id' => 'KPI-07',
            'categoria' => 'operacion',
            'titulo' => 'Cumplimiento de la planificación',
            'es_proyeccion' => false,
            'sin_datos_suficientes' => false,
            'mensaje_sin_datos' => self::MENSAJE_SIN_DATOS,
            'valor' => $valor,
            'unidad' => '%',
            'valor_formateado' => number_format($valor, 1, ',', '.').' %',
            'meta' => '≥ 85 % de confiabilidad',
            'meta_numerica' => 85.0,
            'semaforo' => $semaforo,
            'tendencia_pct' => $tendencia,
            'tendencia_positiva' => $tendencia === null ? null : $tendencia >= 0,
            'interpretacion' => $interpretacion,
            'como_se_calcula' => $formula,
            'vistas_disponibles' => ['linea', 'barras', 'tabla'],
            'series_linea' => $this->seriePlanificacionPorCubo($ctx['rutas'], $agrupacion),
            'comparacion_barras' => array_map(static fn (array $r): array => [
                'etiqueta' => "Ruta #{$r['id']} ({$r['patente']})",
                'valor' => $r['indice'],
                'unidad' => '%',
                'detalle' => "{$r['completadas']}/{$r['total']} paradas",
            ], $calc['por_ruta']),
            'tabla' => [
                'columnas' => ['Ruta', 'Fecha', 'Paradas', 'Desvío ETA', 'Km real / est.', 'Índice'],
                'filas' => array_map(static fn (array $r): array => [
                    "#{$r['id']} · {$r['patente']}",
                    $r['fecha'],
                    "{$r['completadas']}/{$r['total']}",
                    number_format($r['desvio_eta'], 1, ',', '.').' min',
                    number_format($r['km_real'], 1, ',', '.').' / '.number_format($r['km_est'], 1, ',', '.').' km',
                    number_format($r['indice'], 1, ',', '.').' %',
                ], $calc['por_ruta']),
            ],
            'metricas_usadas' => [
                [
                    'nombre' => 'Paradas completadas',
                    'valor' => number_format($calc['paradas_pct'], 1, ',', '.').' %',
                    'valor_numerico' => $calc['paradas_pct'],
                    'unidad' => '%',
                    'es_referencia' => false,
                    'serie' => [],
                ],
                [
                    'nombre' => 'Desviación ETA promedio',
                    'valor' => number_format($calc['desvio_eta_min'], 1, ',', '.').' min',
                    'valor_numerico' => $calc['desvio_eta_min'],
                    'unidad' => 'min',
                    'es_referencia' => false,
                    'serie' => [],
                ],
                [
                    'nombre' => 'Desviación km reales vs OSRM',
                    'valor' => number_format($calc['desvio_km_pct'], 1, ',', '.').' %',
                    'valor_numerico' => $calc['desvio_km_pct'],
                    'unidad' => '%',
                    'es_referencia' => false,
                    'serie' => [],
                ],
            ],
            'extras' => [],
        ];
    }

    /**
     * KPI 8: Confiabilidad de los datos de sensores (reciente < 8 h, batería ≥ 3,40 V).
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function kpi08ConfiabilidadSensores(array $ctx): array
    {
        $formula = '(Puntos activos con sensor IoT reportando en < 8 h y batería ≥ 3,40 V ÷ total de puntos activos) × 100';

        /** @var Collection<int, Punto> $activos */
        $activos = $ctx['puntos']->where('activo', true)->values();

        if ($activos->isEmpty()) {
            return $this->kpiSinDatos(
                id: 'KPI-08',
                categoria: 'operacion',
                titulo: 'Confiabilidad de datos de sensores',
                meta: '≥ 90 % puntos con dato confiable',
                metaNumerica: 90.0,
                unidad: '%',
                comoSeCalcula: $formula,
                vistas: ['barras', 'tabla'],
            );
        }

        $ahora = now();
        $confiables = 0;
        $sinReporteReciente = 0;
        $bateriaBaja = 0;
        $soloManual = 0;
        $porSector = [];
        $filas = [];

        foreach ($activos as $p) {
            $sector = $p->sectorResuelto();
            $porSector[$sector] ??= ['confiables' => 0, 'total' => 0];
            $porSector[$sector]['total']++;

            $tieneSensor = $p->tieneSensorVinculado();
            $horasDesdeHeartbeat = $p->ultimo_heartbeat_iot instanceof CarbonInterface
                ? round($p->ultimo_heartbeat_iot->diffInMinutes($ahora, false) / 60.0, 1)
                : null;
            $reciente = $horasDesdeHeartbeat !== null && $horasDesdeHeartbeat >= 0 && $horasDesdeHeartbeat < self::MAX_HORAS_SENSOR_RECIENTE;
            $voltaje = $p->bateria_voltaje !== null ? (float) $p->bateria_voltaje : null;
            $bateriaOk = $voltaje === null || $voltaje >= self::VOLTAJE_MINIMO_BATERIA;

            if (! $tieneSensor) {
                $soloManual++;
                $estado = 'Registro manual';
            } elseif (! $reciente) {
                $sinReporteReciente++;
                $estado = 'Sensor sin reporte (< 8 h)';
            } elseif (! $bateriaOk) {
                $bateriaBaja++;
                $estado = 'Batería baja';
            } else {
                $confiables++;
                $porSector[$sector]['confiables']++;
                $estado = 'Confiable';
            }

            $filas[] = [
                $p->nombre,
                $sector,
                $tieneSensor ? ($p->sensor_codigo ?? 'ESP32') : 'Sin sensor',
                $horasDesdeHeartbeat !== null ? number_format($horasDesdeHeartbeat, 1, ',', '.').' h' : 'Nunca',
                $voltaje !== null ? number_format($voltaje, 2, ',', '.').' V' : '—',
                $estado,
            ];
        }

        $valor = round(($confiables / $activos->count()) * 100, 1);
        $semaforo = $valor >= 90.0 ? 'cumple' : ($valor >= 75.0 ? 'cerca' : 'no_cumple');

        $interpretacion = sprintf(
            '%s %% de los puntos activos (%d de %d) tienen dato IoT confiable (< 8 h y batería sana); %d dependen de carga manual y %d requieren mantenimiento.',
            number_format($valor, 1, ',', '.'),
            $confiables,
            $activos->count(),
            $soloManual,
            $sinReporteReciente + $bateriaBaja,
        );

        $barras = [];
        foreach ($porSector as $sector => $s) {
            $pct = round(($s['confiables'] / max(1, $s['total'])) * 100, 1);
            $barras[] = [
                'etiqueta' => $sector,
                'valor' => $pct,
                'unidad' => '%',
                'detalle' => "{$s['confiables']}/{$s['total']} confiables",
            ];
        }

        return [
            'id' => 'KPI-08',
            'categoria' => 'operacion',
            'titulo' => 'Confiabilidad de datos de sensores',
            'es_proyeccion' => false,
            'sin_datos_suficientes' => false,
            'mensaje_sin_datos' => self::MENSAJE_SIN_DATOS,
            'valor' => $valor,
            'unidad' => '%',
            'valor_formateado' => number_format($valor, 1, ',', '.').' %',
            'meta' => '≥ 90 % puntos con dato confiable',
            'meta_numerica' => 90.0,
            'semaforo' => $semaforo,
            'tendencia_pct' => null,
            'tendencia_positiva' => null,
            'interpretacion' => $interpretacion,
            'como_se_calcula' => $formula,
            'vistas_disponibles' => ['barras', 'tabla'],
            'series_linea' => [],
            'comparacion_barras' => $barras,
            'tabla' => [
                'columnas' => ['Punto', 'Sector', 'Sensor', 'Últ. lectura', 'Batería', 'Estado'],
                'filas' => $filas,
            ],
            'metricas_usadas' => [
                [
                    'nombre' => 'Sensores confiables (< 8 h)',
                    'valor' => "{$confiables} de {$activos->count()}",
                    'valor_numerico' => (float) $confiables,
                    'unidad' => 'puntos',
                    'es_referencia' => false,
                    'serie' => $barras,
                ],
                [
                    'nombre' => 'Sin reporte reciente (≥ 8 h)',
                    'valor' => (string) $sinReporteReciente,
                    'valor_numerico' => (float) $sinReporteReciente,
                    'unidad' => 'sensores',
                    'es_referencia' => false,
                    'serie' => [],
                ],
                [
                    'nombre' => 'Batería baja (< 3,40 V)',
                    'valor' => (string) $bateriaBaja,
                    'valor_numerico' => (float) $bateriaBaja,
                    'unidad' => 'sensores',
                    'es_referencia' => false,
                    'serie' => [],
                ],
                [
                    'nombre' => 'Dependientes de registro manual',
                    'valor' => (string) $soloManual,
                    'valor_numerico' => (float) $soloManual,
                    'unidad' => 'puntos',
                    'es_referencia' => false,
                    'serie' => [],
                ],
            ],
            'extras' => [],
        ];
    }

    /**
     * KPI 9: Flota necesaria para mañana (proyección bajo demanda, sin depender
     * de cron). Calcula y persiste la proyección de mañana y evalúa días previos.
     *
     * @param  array<string, array<string, mixed>>  $params
     * @return array<string, mixed>
     */
    private function kpi09FlotaNecesariaManana(array $params): array
    {
        $umbral = (float) $params[ParametroOperativo::UMBRAL_VISITA_UTIL_PCT]['valor'];
        $formula = 'Proyección: (puntos con llenado actual + velocidad diaria ≥ umbral) ÷ promedio histórico de puntos atendidos por camión por jornada. Se autoevalúa al día siguiente contra los puntos reales.';

        // 1. Evaluar bajo demanda proyecciones pasadas aún sin contraste real.
        $this->evaluarProyeccionesPendientes($umbral);

        $puntosActivos = Punto::query()->where('activo', true)->get();
        $lecturasRecientes = LecturaPunto::query()
            ->where('medido_en', '>=', now()->subDays(14))
            ->orderBy('medido_en')
            ->get()
            ->groupBy('punto_id');

        $rutasHistoricas = Ruta::query()
            ->where('estado', Ruta::ESTADO_COMPLETADA)
            ->where('fecha_programada', '>=', DiaDeNegocio::hoy()->subDays(30)->format('Y-m-d'))
            ->with('paradas')
            ->get();

        $paradasPorRuta = [];
        foreach ($rutasHistoricas as $r) {
            $recolectadas = $r->paradas->where('estado_parada', RutaPunto::ESTADO_RECOLECTADO)->count();
            if ($recolectadas > 0) {
                $paradasPorRuta[] = $recolectadas;
            }
        }

        $puntosConVelocidad = 0;
        $puntosListosManana = 0;
        $detallePuntos = [];

        foreach ($puntosActivos as $p) {
            $velDia = $this->velocidadLlenadoPunto($lecturasRecientes->get($p->id) ?? collect());
            if ($velDia !== null) {
                $puntosConVelocidad++;
            }
            $proyectado = min(100.0, (float) $p->porcentaje_llenado + ($velDia ?? 0.0));
            $listoManana = $proyectado >= $umbral;
            if ($listoManana) {
                $puntosListosManana++;
            }
            $detallePuntos[] = [
                'nombre' => $p->nombre,
                'sector' => $p->sectorResuelto(),
                'actual' => (int) $p->porcentaje_llenado,
                'vel_dia' => $velDia,
                'proyectado' => round($proyectado, 0),
                'listo' => $listoManana,
            ];
        }

        if ($puntosActivos->isEmpty() || $paradasPorRuta === [] || ($puntosConVelocidad === 0 && $puntosListosManana === 0)) {
            return $this->kpiSinDatos(
                id: 'KPI-09',
                categoria: 'operacion',
                titulo: 'Flota necesaria para mañana (proyección)',
                meta: 'Acierto de proyección > 85 %',
                metaNumerica: 85.0,
                unidad: 'camiones',
                comoSeCalcula: $formula,
                vistas: ['linea', 'tabla'],
                esProyeccion: true,
            );
        }

        $capacidadCamionDia = array_sum($paradasPorRuta) / count($paradasPorRuta);
        $camionesNecesarios = $puntosListosManana === 0
            ? 0
            : (int) ceil($puntosListosManana / max(1.0, $capacidadCamionDia));

        // 2. Persistir/actualizar bajo demanda la proyección para mañana (idempotente).
        $manana = DiaDeNegocio::hoy()->addDay()->format('Y-m-d');
        ProyeccionFlota::updateOrCreate(
            ['fecha_objetivo' => $manana],
            [
                'puntos_proyectados' => $puntosListosManana,
                'camiones_proyectados' => $camionesNecesarios,
                'calculada_en' => now(),
            ],
        );

        $evaluadas = ProyeccionFlota::query()
            ->whereNotNull('porcentaje_acierto')
            ->orderBy('fecha_objetivo')
            ->limit(30)
            ->get();

        $aciertoPromedio = $evaluadas->isNotEmpty()
            ? round((float) $evaluadas->avg('porcentaje_acierto'), 1)
            : null;
        $ultimoAcierto = $evaluadas->last()?->porcentaje_acierto !== null
            ? (float) $evaluadas->last()->porcentaje_acierto
            : null;

        $semaforo = $aciertoPromedio === null
            ? 'cerca'
            : ($aciertoPromedio > 85.0 ? 'cumple' : ($aciertoPromedio >= 70.0 ? 'cerca' : 'no_cumple'));

        $textoAcierto = $aciertoPromedio !== null
            ? sprintf(' Acierto histórico de la proyección: %s %% (meta > 85 %%).', number_format($aciertoPromedio, 1, ',', '.'))
            : ' Aún sin días anteriores evaluados para medir el % de acierto.';

        $interpretacion = sprintf(
            'Proyección para mañana (%s): se esperan %d punto(s) listo(s) (≥ %d %%). Se necesitan %d camión(es) según capacidad histórica de %s puntos/camión.%s',
            $manana,
            $puntosListosManana,
            (int) round($umbral),
            $camionesNecesarios,
            number_format($capacidadCamionDia, 1, ',', '.'),
            $textoAcierto,
        );

        usort($detallePuntos, static fn (array $a, array $b): int => $b['proyectado'] <=> $a['proyectado']);

        return [
            'id' => 'KPI-09',
            'categoria' => 'operacion',
            'titulo' => 'Flota necesaria para mañana (proyección)',
            'es_proyeccion' => true,
            'sin_datos_suficientes' => false,
            'mensaje_sin_datos' => self::MENSAJE_SIN_DATOS,
            'valor' => (float) $camionesNecesarios,
            'unidad' => 'camiones',
            'valor_formateado' => $camionesNecesarios === 1 ? '1 camión' : "{$camionesNecesarios} camiones",
            'meta' => 'Acierto de proyección > 85 %',
            'meta_numerica' => 85.0,
            'semaforo' => $semaforo,
            'tendencia_pct' => null,
            'tendencia_positiva' => null,
            'interpretacion' => $interpretacion,
            'como_se_calcula' => $formula,
            'vistas_disponibles' => ['linea', 'tabla'],
            // La serie mide el % de acierto, no camiones: la unidad viaja en cada punto.
            'series_linea' => $evaluadas->map(static fn (ProyeccionFlota $pf): array => [
                'etiqueta' => $pf->fecha_objetivo->format('d-m'),
                'valor' => round((float) $pf->porcentaje_acierto, 1),
                'unidad' => '%',
            ])->values()->all(),
            'comparacion_barras' => [],
            'tabla' => [
                'columnas' => ['Punto', 'Sector', 'Actual', 'Llenado/día', 'Proyectado mañana', 'Estado'],
                'filas' => array_map(static fn (array $d): array => [
                    $d['nombre'],
                    $d['sector'],
                    "{$d['actual']} %",
                    $d['vel_dia'] !== null ? number_format($d['vel_dia'], 1, ',', '.').' %' : '—',
                    "{$d['proyectado']} %",
                    $d['listo'] ? 'Listo mañana' : 'Esperar',
                ], $detallePuntos),
            ],
            'metricas_usadas' => [
                [
                    'nombre' => 'Puntos proyectados listos mañana',
                    'valor' => (string) $puntosListosManana,
                    'valor_numerico' => (float) $puntosListosManana,
                    'unidad' => 'puntos',
                    'es_referencia' => (bool) $params[ParametroOperativo::UMBRAL_VISITA_UTIL_PCT]['es_referencia'],
                    'serie' => [],
                ],
                [
                    'nombre' => 'Capacidad promedio por camión',
                    'valor' => number_format($capacidadCamionDia, 1, ',', '.').' pts/jornada',
                    'valor_numerico' => round($capacidadCamionDia, 1),
                    'unidad' => 'pts/camión',
                    'es_referencia' => false,
                    'serie' => [],
                ],
                [
                    'nombre' => 'Acierto histórico de proyección',
                    'valor' => $aciertoPromedio !== null ? number_format($aciertoPromedio, 1, ',', '.').' %' : 'Sin historial aún',
                    'valor_numerico' => $aciertoPromedio ?? 0.0,
                    'unidad' => '%',
                    'es_referencia' => false,
                    'serie' => $evaluadas->map(static fn (ProyeccionFlota $pf): array => [
                        'etiqueta' => $pf->fecha_objetivo->format('d-m'),
                        'valor' => round((float) $pf->porcentaje_acierto, 1),
                    ])->values()->all(),
                ],
            ],
            'extras' => [
                'fecha_objetivo' => $manana,
                'puntos_proyectados' => $puntosListosManana,
                'camiones_proyectados' => $camionesNecesarios,
                'acierto_proyeccion_pct' => $aciertoPromedio,
                'ultimo_acierto_pct' => $ultimoAcierto,
            ],
        ];
    }

    /**
     * KPI 10: Índice de desempeño del chofer (ranking compuesto normalizado por
     * mediana del sector).
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function kpi10IndiceDesempenoChofer(array $ctx): array
    {
        $formula = 'Puntaje 0–100 ponderado (30 % paradas sin omitir + 25 % puntualidad + 25 % adherencia km + 20 % puntos/hora), ajustando cada factor contra la mediana del sector para equidad';

        $porChofer = [];
        foreach ($ctx['rutas'] as $ruta) {
            if (! $ruta->chofer || $ruta->paradas->isEmpty()) {
                continue;
            }
            $choferId = $ruta->chofer_id;
            $sectorDominante = $this->sectorDominanteRuta($ruta);

            $planificadas = $ruta->paradas->count();
            $completadas = $ruta->paradas->where('estado_parada', RutaPunto::ESTADO_RECOLECTADO)->count();

            $desviosEta = [];
            foreach ($ruta->paradas as $p) {
                if ($p->hora_estimada_llegada && $p->hora_real_recoleccion) {
                    $desviosEta[] = abs($p->hora_estimada_llegada->diffInMinutes($p->hora_real_recoleccion, false));
                }
            }
            $etaProm = $desviosEta !== [] ? array_sum($desviosEta) / count($desviosEta) : 0.0;
            $puntualidad = max(0.0, 100.0 - ($etaProm * 4.0));

            $kmEst = (float) $ruta->distancia_estimada_km;
            $kmReal = (float) $ruta->distancia_real_km > 0 ? (float) $ruta->distancia_real_km : $kmEst;
            $desvioKmPct = $kmEst > 0 ? max(0.0, (($kmReal - $kmEst) / $kmEst) * 100.0) : 0.0;
            $adherenciaKm = max(0.0, 100.0 - $desvioKmPct);

            $horas = max(0.25, $this->horasDeRuta($ruta));

            $porChofer[$choferId] ??= [
                'nombre' => $ruta->chofer->nombre_cifrado,
                'sectores' => [],
                'planificadas' => 0,
                'completadas' => 0,
                'puntualidades' => [],
                'adherencias_km' => [],
                'horas' => 0.0,
            ];
            $porChofer[$choferId]['sectores'][] = $sectorDominante;
            $porChofer[$choferId]['planificadas'] += $planificadas;
            $porChofer[$choferId]['completadas'] += $completadas;
            $porChofer[$choferId]['puntualidades'][] = $puntualidad;
            $porChofer[$choferId]['adherencias_km'][] = $adherenciaKm;
            $porChofer[$choferId]['horas'] += $horas;
        }

        if ($porChofer === []) {
            return $this->kpiSinDatos(
                id: 'KPI-10',
                categoria: 'choferes',
                titulo: 'Índice de desempeño del chofer',
                meta: '≥ 80 pts promedio',
                metaNumerica: 80.0,
                unidad: 'pts',
                comoSeCalcula: $formula,
                vistas: ['barras', 'tabla'],
            );
        }

        $crudos = [];
        $porSectorFactores = [];
        foreach ($porChofer as $id => $d) {
            $conteos = array_count_values($d['sectores']);
            arsort($conteos);
            $sector = (string) array_key_first($conteos);

            $completitud = ($d['completadas'] / max(1, $d['planificadas'])) * 100.0;
            $puntualidad = array_sum($d['puntualidades']) / count($d['puntualidades']);
            $adherencia = array_sum($d['adherencias_km']) / count($d['adherencias_km']);
            $productividad = $d['completadas'] / max(0.25, $d['horas']);

            $crudos[$id] = [
                'nombre' => $d['nombre'],
                'sector' => $sector,
                'completitud' => $completitud,
                'puntualidad' => $puntualidad,
                'adherencia' => $adherencia,
                'productividad' => $productividad,
            ];
            $porSectorFactores[$sector]['completitud'][] = $completitud;
            $porSectorFactores[$sector]['puntualidad'][] = $puntualidad;
            $porSectorFactores[$sector]['adherencia'][] = $adherencia;
            $porSectorFactores[$sector]['productividad'][] = $productividad;
        }

        $ranking = [];
        foreach ($crudos as $c) {
            $sec = $c['sector'];
            $mComp = $this->mediana($porSectorFactores[$sec]['completitud']);
            $mPunt = $this->mediana($porSectorFactores[$sec]['puntualidad']);
            $mAdh = $this->mediana($porSectorFactores[$sec]['adherencia']);
            $mProd = $this->mediana($porSectorFactores[$sec]['productividad']);

            $nComp = $this->normalizarContraMediana($c['completitud'], $mComp);
            $nPunt = $this->normalizarContraMediana($c['puntualidad'], $mPunt);
            $nAdh = $this->normalizarContraMediana($c['adherencia'], $mAdh);
            $nProd = $this->normalizarContraMediana($c['productividad'], $mProd);

            $aComp = round($nComp * 0.30, 1);
            $aPunt = round($nPunt * 0.25, 1);
            $aAdh = round($nAdh * 0.25, 1);
            $aProd = round($nProd * 0.20, 1);
            $puntaje = round($aComp + $aPunt + $aAdh + $aProd, 1);

            $ranking[] = [
                'nombre' => $c['nombre'],
                'sector' => $sec,
                'puntaje' => $puntaje,
                'aporte_completitud' => $aComp,
                'aporte_puntualidad' => $aPunt,
                'aporte_km' => $aAdh,
                'aporte_productividad' => $aProd,
            ];
        }

        usort($ranking, static fn (array $a, array $b): int => $b['puntaje'] <=> $a['puntaje']);
        $promedio = round(array_sum(array_column($ranking, 'puntaje')) / count($ranking), 1);
        $semaforo = $promedio >= 80.0 ? 'cumple' : ($promedio >= 65.0 ? 'cerca' : 'no_cumple');
        $lider = $ranking[0];

        $interpretacion = sprintf(
            'Desempeño promedio de %s pts (ajustado por dificultad del sector). Mejor puntaje: %s con %s pts en sector %s.',
            number_format($promedio, 1, ',', '.'),
            $lider['nombre'],
            number_format($lider['puntaje'], 1, ',', '.'),
            $lider['sector'],
        );

        return [
            'id' => 'KPI-10',
            'categoria' => 'choferes',
            'titulo' => 'Índice de desempeño del chofer',
            'es_proyeccion' => false,
            'sin_datos_suficientes' => false,
            'mensaje_sin_datos' => self::MENSAJE_SIN_DATOS,
            'valor' => $promedio,
            'unidad' => 'pts',
            'valor_formateado' => number_format($promedio, 1, ',', '.').' pts',
            'meta' => '≥ 80 pts promedio',
            'meta_numerica' => 80.0,
            'semaforo' => $semaforo,
            'tendencia_pct' => null,
            'tendencia_positiva' => null,
            'interpretacion' => $interpretacion,
            'como_se_calcula' => $formula,
            'vistas_disponibles' => ['barras', 'tabla'],
            'series_linea' => [],
            'comparacion_barras' => array_map(static fn (array $r): array => [
                'etiqueta' => $r['nombre'],
                'valor' => $r['puntaje'],
                'unidad' => 'pts',
                'detalle' => "Sector {$r['sector']}",
            ], $ranking),
            'tabla' => [
                'columnas' => ['Chofer', 'Sector', 'Paradas (30)', 'Puntual. (25)', 'Km (25)', 'Pts/h (20)', 'Total'],
                'filas' => array_map(static fn (array $r): array => [
                    $r['nombre'],
                    $r['sector'],
                    number_format($r['aporte_completitud'], 1, ',', '.'),
                    number_format($r['aporte_puntualidad'], 1, ',', '.'),
                    number_format($r['aporte_km'], 1, ',', '.'),
                    number_format($r['aporte_productividad'], 1, ',', '.'),
                    number_format($r['puntaje'], 1, ',', '.').' pts',
                ], $ranking),
            ],
            'metricas_usadas' => [
                [
                    'nombre' => 'Aporte promedio paradas sin omitir',
                    'valor' => number_format(array_sum(array_column($ranking, 'aporte_completitud')) / count($ranking), 1, ',', '.').' / 30',
                    'valor_numerico' => round(array_sum(array_column($ranking, 'aporte_completitud')) / count($ranking), 1),
                    'unidad' => 'pts',
                    'es_referencia' => false,
                    'serie' => [],
                ],
                [
                    'nombre' => 'Aporte promedio puntualidad',
                    'valor' => number_format(array_sum(array_column($ranking, 'aporte_puntualidad')) / count($ranking), 1, ',', '.').' / 25',
                    'valor_numerico' => round(array_sum(array_column($ranking, 'aporte_puntualidad')) / count($ranking), 1),
                    'unidad' => 'pts',
                    'es_referencia' => false,
                    'serie' => [],
                ],
                [
                    'nombre' => 'Aporte promedio adherencia km',
                    'valor' => number_format(array_sum(array_column($ranking, 'aporte_km')) / count($ranking), 1, ',', '.').' / 25',
                    'valor_numerico' => round(array_sum(array_column($ranking, 'aporte_km')) / count($ranking), 1),
                    'unidad' => 'pts',
                    'es_referencia' => false,
                    'serie' => [],
                ],
                [
                    'nombre' => 'Aporte promedio productividad (pts/h)',
                    'valor' => number_format(array_sum(array_column($ranking, 'aporte_productividad')) / count($ranking), 1, ',', '.').' / 20',
                    'valor_numerico' => round(array_sum(array_column($ranking, 'aporte_productividad')) / count($ranking), 1),
                    'unidad' => 'pts',
                    'es_referencia' => false,
                    'serie' => [],
                ],
            ],
            'extras' => ['ranking' => $ranking],
        ];
    }

    /**
     * KPI 11: Costo extra atribuible a la conducción (desvíos km + excesos > 50 km/h).
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $prev
     * @param  array<string, array<string, mixed>>  $params
     * @return array<string, mixed>
     */
    private function kpi11CostoExtraConduccion(array $ctx, array $prev, array $params, string $agrupacion): array
    {
        $calc = $this->calcularCostoConduccion($ctx, $params);
        $calcPrev = $this->calcularCostoConduccion($prev, $params);
        $limiteVel = (int) round((float) $params[ParametroOperativo::LIMITE_VELOCIDAD_KMH]['valor']);

        $formula = "((Km fuera de ruta ÷ rendimiento) + (15 % de recargo sobre tramos con exceso > {$limiteVel} km/h)) × precio combustible";

        if ($calc['rutas_con_gps'] === 0) {
            return $this->kpiSinDatos(
                id: 'KPI-11',
                categoria: 'choferes',
                titulo: 'Costo extra atribuible a la conducción',
                meta: '≤ $15.000 en el período',
                metaNumerica: 15000.0,
                unidad: '$',
                comoSeCalcula: $formula,
                vistas: ['linea', 'barras', 'tabla'],
            );
        }

        $valor = $calc['pesos_extra'];
        $valorPrev = $calcPrev['rutas_con_gps'] > 0 ? $calcPrev['pesos_extra'] : null;
        $tendencia = $this->calcularTendencia($valor, $valorPrev);
        $semaforo = $valor <= 15000 ? 'cumple' : ($valor <= 35000 ? 'cerca' : 'no_cumple');

        $interpretacion = sprintf(
            'La conducción generó %s L extra (%s) por %s km fuera de ruta y %d ping(s) sobre %d km/h%s.',
            number_format($calc['litros_extra'], 2, ',', '.'),
            $this->formatoPesos($valor),
            number_format($calc['km_extra'], 1, ',', '.'),
            $calc['pings_exceso'],
            $limiteVel,
            $this->textoTendencia($tendencia, menorEsMejor: true),
        );

        $barras = [];
        foreach ($calc['por_chofer'] as $c) {
            $barras[] = [
                'etiqueta' => $c['nombre'],
                'valor' => $c['pesos_extra'],
                'unidad' => '$',
                'detalle' => number_format($c['litros_extra'], 2, ',', '.')." L · {$c['pings_exceso']} excesos",
            ];
        }
        usort($barras, static fn (array $a, array $b): int => $b['valor'] <=> $a['valor']);

        return [
            'id' => 'KPI-11',
            'categoria' => 'choferes',
            'titulo' => 'Costo extra atribuible a la conducción',
            'es_proyeccion' => false,
            'sin_datos_suficientes' => false,
            'mensaje_sin_datos' => self::MENSAJE_SIN_DATOS,
            'valor' => $valor,
            'unidad' => '$',
            'valor_formateado' => $this->formatoPesos($valor),
            'meta' => '≤ $15.000 en el período',
            'meta_numerica' => 15000.0,
            'semaforo' => $semaforo,
            'tendencia_pct' => $tendencia,
            'tendencia_positiva' => $tendencia === null ? null : $tendencia <= 0,
            'interpretacion' => $interpretacion,
            'como_se_calcula' => $formula,
            'vistas_disponibles' => ['linea', 'barras', 'tabla'],
            'series_linea' => $this->serieConduccionPorCubo($calc['por_ruta'], $agrupacion),
            'comparacion_barras' => $barras,
            'tabla' => [
                'columnas' => ['Ruta', 'Chofer', 'Km extra', "Excesos >{$limiteVel}", 'Litros extra', 'Costo extra'],
                'filas' => array_map(static fn (array $r): array => [
                    "#{$r['ruta_id']} ({$r['fecha']})",
                    $r['chofer'],
                    number_format($r['km_extra'], 1, ',', '.').' km',
                    (string) $r['pings_exceso'],
                    number_format($r['litros_extra'], 2, ',', '.').' L',
                    '$'.number_format($r['pesos_extra'], 0, ',', '.'),
                ], $calc['por_ruta']),
            ],
            'metricas_usadas' => [
                [
                    'nombre' => 'Km fuera de ruta planificada',
                    'valor' => number_format($calc['km_extra'], 1, ',', '.').' km',
                    'valor_numerico' => $calc['km_extra'],
                    'unidad' => 'km',
                    'es_referencia' => false,
                    'serie' => [],
                ],
                [
                    'nombre' => "Excesos de velocidad (> {$limiteVel} km/h)",
                    'valor' => "{$calc['pings_exceso']} lecturas GPS",
                    'valor_numerico' => (float) $calc['pings_exceso'],
                    'unidad' => 'pings',
                    'es_referencia' => (bool) $params[ParametroOperativo::LIMITE_VELOCIDAD_KMH]['es_referencia'],
                    'serie' => [],
                ],
                [
                    'nombre' => 'Litros extra estimados',
                    'valor' => number_format($calc['litros_extra'], 2, ',', '.').' L',
                    'valor_numerico' => $calc['litros_extra'],
                    'unidad' => 'L',
                    'es_referencia' => $calc['uso_rendimiento_referencia'],
                    'serie' => [],
                ],
            ],
            'extras' => [
                'litros_extra' => $calc['litros_extra'],
                'pesos_extra' => $calc['pesos_extra'],
                'km_extra' => $calc['km_extra'],
                'pings_exceso' => $calc['pings_exceso'],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers de cálculo internos
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, array<string, mixed>>  $params
     * @return array<string, mixed>
     */
    private function calcularCostosOperativos(array $ctx, array $params): array
    {
        $precioCombustible = (float) $params[ParametroOperativo::PRECIO_COMBUSTIBLE_LITRO]['valor'];
        $rendRef = (float) $params[ParametroOperativo::RENDIMIENTO_REFERENCIA_KM_L]['valor'];
        $arriendoRef = (float) $params[ParametroOperativo::ARRIENDO_REFERENCIA_DIARIO]['valor'];

        $rutasPorCamion = $ctx['rutas']->groupBy('camion_id');
        $porCamion = [];
        $porSector = [];
        $costoCombustibleTotal = 0.0;
        $costoArriendoTotal = 0.0;
        $kmTotales = 0.0;
        $puntosVaciadosTotal = 0;
        $diasArrendadosTotales = 0;
        $usoArriendoRef = false;

        foreach ($ctx['camiones'] as $camion) {
            $diasArrendados = $ctx['dias_arrendados'][$camion->id] ?? 0;
            if ($diasArrendados <= 0) {
                continue;
            }
            $diasArrendadosTotales += $diasArrendados;

            $rend = $camion->rendimiento_km_l !== null ? (float) $camion->rendimiento_km_l : $rendRef;
            $arriendoDiario = $camion->costo_arriendo_diario !== null ? (float) $camion->costo_arriendo_diario : $arriendoRef;
            if ($camion->costo_arriendo_diario === null) {
                $usoArriendoRef = true;
            }

            /** @var Collection<int, Ruta> $rutasCamion */
            $rutasCamion = $rutasPorCamion->get($camion->id) ?? collect();
            $kmCamion = 0.0;
            $puntosCamion = 0;

            foreach ($rutasCamion as $ruta) {
                $km = $this->kmDeRuta($ruta);
                $kmCamion += $km;
                $vaciadosRuta = $ruta->paradas->where('estado_parada', RutaPunto::ESTADO_RECOLECTADO);
                $puntosCamion += $vaciadosRuta->count();

                foreach ($vaciadosRuta as $parada) {
                    $sector = $parada->punto instanceof Punto ? $parada->punto->sectorResuelto() : Punto::SIN_SECTOR;
                    $porSector[$sector] = ($porSector[$sector] ?? 0) + 1;
                }
            }

            $comb = ($kmCamion * $precioCombustible) / max(0.1, $rend);
            $arr = $arriendoDiario * $diasArrendados;

            $costoCombustibleTotal += $comb;
            $costoArriendoTotal += $arr;
            $kmTotales += $kmCamion;
            $puntosVaciadosTotal += $puntosCamion;

            $porCamion[] = [
                'camion_id' => $camion->id,
                'patente' => $camion->patente,
                'dias_arrendados' => $diasArrendados,
                'km' => round($kmCamion, 2),
                'puntos' => $puntosCamion,
                'costo_combustible' => round($comb, 2),
                'costo_arriendo' => round($arr, 2),
                'costo_total' => round($comb + $arr, 2),
            ];
        }

        return [
            'costo_combustible' => $costoCombustibleTotal,
            'costo_arriendo' => $costoArriendoTotal,
            'costo_total' => $costoCombustibleTotal + $costoArriendoTotal,
            'km_totales' => $kmTotales,
            'puntos_vaciados' => $puntosVaciadosTotal,
            'dias_arrendados_totales' => $diasArrendadosTotales,
            'por_camion' => $porCamion,
            'por_sector' => $porSector,
            'uso_arriendo_referencia' => $usoArriendoRef,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, array<string, mixed>>  $params
     * @return array<string, mixed>
     */
    private function calcularAhorro(array $ctx, array $params): array
    {
        $costos = $this->calcularCostosOperativos($ctx, $params);
        $evaluados = 0;
        $excluidos = 0;
        $incluidos = 0;
        $totalKm = 0.0;
        $totalMin = 0.0;

        foreach ($ctx['rutas'] as $ruta) {
            $evaluados += (int) $ruta->total_puntos_evaluados;
            $excluidos += (int) $ruta->puntos_excluidos_count;
            $incluidos += (int) $ruta->puntos_incluidos_count;
            $totalKm += $this->kmDeRuta($ruta);
            $totalMin += $this->horasDeRuta($ruta) * 60.0;
        }

        $kmPorParada = $incluidos > 0 ? $totalKm / $incluidos : 0.0;
        $minPorParada = $incluidos > 0 ? $totalMin / $incluidos : 0.0;
        $kmAhorrados = round($excluidos * $kmPorParada, 2);
        $horasAhorradas = round(($excluidos * $minPorParada) / 60.0, 2);

        $precioComb = (float) $params[ParametroOperativo::PRECIO_COMBUSTIBLE_LITRO]['valor'];
        $rendRef = (float) $params[ParametroOperativo::RENDIMIENTO_REFERENCIA_KM_L]['valor'];
        $costoPorKm = $totalKm > 0
            ? ($costos['costo_total'] / $totalKm)
            : ($precioComb / max(0.1, $rendRef));
        $pesosAhorrados = round($kmAhorrados * $costoPorKm, 0);

        return [
            'evaluados' => $evaluados,
            'excluidos' => $excluidos,
            'incluidos' => $incluidos,
            'porcentaje_excluidos' => $evaluados > 0 ? round(($excluidos / $evaluados) * 100, 1) : 0.0,
            'km_por_parada' => $kmPorParada,
            'km_ahorrados' => $kmAhorrados,
            'horas_ahorradas' => $horasAhorradas,
            'costo_por_km' => round($costoPorKm, 2),
            'pesos_ahorrados' => $pesosAhorrados,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, array<string, mixed>>  $params
     * @return array<string, mixed>
     */
    private function calcularUsoFlota(array $ctx, array $params): array
    {
        $horasJornada = (float) $params[ParametroOperativo::HORAS_JORNADA]['valor'];
        $rutasPorCamion = $ctx['rutas']->groupBy('camion_id');

        $horasRutaTotal = 0.0;
        $horasDispTotal = 0.0;
        $diasArrendTotal = 0;
        $diasSinSalidaTotal = 0;
        $porCamion = [];

        foreach ($ctx['camiones'] as $camion) {
            $diasArrend = $ctx['dias_arrendados'][$camion->id] ?? 0;
            if ($diasArrend <= 0) {
                continue;
            }
            $rutasCamion = $rutasPorCamion->get($camion->id) ?? collect();
            $fechasSalida = [];
            $horasCamion = 0.0;
            $puntosCamion = 0;

            foreach ($rutasCamion as $ruta) {
                $fechasSalida[$ruta->fecha_programada->format('Y-m-d')] = true;
                $horasCamion += $this->horasDeRuta($ruta);
                $puntosCamion += $ruta->paradas->where('estado_parada', RutaPunto::ESTADO_RECOLECTADO)->count();
            }

            $diasConSalida = min($diasArrend, count($fechasSalida));
            $diasSinSalida = max(0, $diasArrend - $diasConSalida);
            $horasDisp = $diasArrend * $horasJornada;
            $usoPct = $horasDisp > 0 ? round(min(100.0, ($horasCamion / $horasDisp) * 100), 1) : 0.0;

            $horasRutaTotal += $horasCamion;
            $horasDispTotal += $horasDisp;
            $diasArrendTotal += $diasArrend;
            $diasSinSalidaTotal += $diasSinSalida;

            $porCamion[] = [
                'patente' => $camion->patente,
                'dias_arrendados' => $diasArrend,
                'dias_con_salida' => $diasConSalida,
                'dias_sin_salida' => $diasSinSalida,
                'horas_en_ruta' => round($horasCamion, 2),
                'horas_disponibles' => round($horasDisp, 2),
                'puntos' => $puntosCamion,
                'uso_pct' => $usoPct,
            ];
        }

        $usoGlobal = $horasDispTotal > 0 ? round(min(100.0, ($horasRutaTotal / $horasDispTotal) * 100), 1) : 0.0;

        return [
            'rutas_totales' => $ctx['rutas']->count(),
            'horas_en_ruta' => round($horasRutaTotal, 2),
            'horas_disponibles' => round($horasDispTotal, 2),
            'dias_arrendados_totales' => $diasArrendTotal,
            'dias_sin_salida_totales' => $diasSinSalidaTotal,
            'uso_pct' => $usoGlobal,
            'por_camion' => $porCamion,
        ];
    }

    /**
     * @param  Collection<int, Ruta>  $rutas
     * @return array<string, mixed>
     */
    private function calcularEfectividad(Collection $rutas, float $umbral): array
    {
        $total = 0;
        $utiles = 0;
        $kmPrematuras = 0.0;
        $sumaLlenado = 0.0;
        $porSector = [];

        foreach ($rutas as $ruta) {
            foreach ($ruta->paradas as $p) {
                if ($p->estado_parada !== RutaPunto::ESTADO_RECOLECTADO || $p->porcentaje_llenado_al_vaciar === null) {
                    continue;
                }
                $total++;
                $pct = (int) $p->porcentaje_llenado_al_vaciar;
                $sumaLlenado += $pct;
                $esUtil = $pct >= $umbral;
                $sector = $p->punto instanceof Punto ? $p->punto->sectorResuelto() : Punto::SIN_SECTOR;
                $porSector[$sector] ??= ['total' => 0, 'utiles' => 0, 'km_prematuras' => 0.0];
                $porSector[$sector]['total']++;

                if ($esUtil) {
                    $utiles++;
                    $porSector[$sector]['utiles']++;
                } else {
                    $kmTramo = (float) ($p->distancia_tramo_km ?? 0);
                    $kmPrematuras += $kmTramo;
                    $porSector[$sector]['km_prematuras'] += $kmTramo;
                }
            }
        }

        return [
            'total' => $total,
            'utiles' => $utiles,
            'pct_utiles' => $total > 0 ? round(($utiles / $total) * 100, 1) : 0.0,
            'km_prematuras' => round($kmPrematuras, 2),
            'llenado_promedio' => $total > 0 ? round($sumaLlenado / $total, 1) : 0.0,
            'por_sector' => $porSector,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function calcularDesbordes(array $ctx): array
    {
        /** @var Collection<int, Punto> $puntos */
        $puntos = $ctx['puntos'];
        /** @var Collection<int, Collection<int, LecturaPunto>> $lecturasPorPunto */
        $lecturasPorPunto = $ctx['lecturas'];

        $recoleccionesPorPunto = [];
        foreach ($ctx['rutas'] as $ruta) {
            foreach ($ruta->paradas as $p) {
                if ($p->estado_parada === RutaPunto::ESTADO_RECOLECTADO && $p->hora_real_recoleccion) {
                    $recoleccionesPorPunto[$p->punto_id][] = $p->hora_real_recoleccion;
                }
            }
        }

        $totalEpisodios = 0;
        $dentroLimite = 0;
        $sumaHoras = 0.0;
        $porSector = [];
        $porPunto = [];
        $cronicos = [];
        $episodiosLista = [];

        $cierreReferencia = now()->lessThan($ctx['fin']) ? now() : $ctx['fin'];

        foreach ($puntos as $punto) {
            /** @var Collection<int, LecturaPunto> $lects */
            $lects = $lecturasPorPunto->get($punto->id) ?? collect();
            if ($lects->isEmpty()) {
                continue;
            }

            $eventos = [];
            foreach ($lects as $l) {
                if ($l->medido_en) {
                    $eventos[] = ['at' => $l->medido_en, 'lleno' => $l->porcentaje_llenado >= 90 || $l->estado_llenado === Punto::ESTADO_LLENO];
                }
            }
            foreach ($recoleccionesPorPunto[$punto->id] ?? [] as $horaRec) {
                $eventos[] = ['at' => $horaRec, 'lleno' => false];
            }

            usort($eventos, static fn (array $a, array $b): int => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

            $inicioLleno = null;
            $episodiosPunto = [];

            foreach ($eventos as $ev) {
                if ($ev['lleno'] && $inicioLleno === null) {
                    $inicioLleno = $ev['at'];
                } elseif (! $ev['lleno'] && $inicioLleno instanceof CarbonInterface) {
                    $episodiosPunto[] = ['inicio' => $inicioLleno, 'fin' => $ev['at']];
                    $inicioLleno = null;
                }
            }
            if ($inicioLleno instanceof CarbonInterface && $inicioLleno->lessThanOrEqualTo($cierreReferencia)) {
                $episodiosPunto[] = ['inicio' => $inicioLleno, 'fin' => $cierreReferencia];
            }

            $epEnPeriodo = array_values(array_filter(
                $episodiosPunto,
                static fn (array $e): bool => $e['fin']->greaterThanOrEqualTo($ctx['inicio']) && $e['inicio']->lessThanOrEqualTo($ctx['fin']),
            ));

            if ($epEnPeriodo === []) {
                continue;
            }

            $sector = $punto->sectorResuelto();
            $porSector[$sector] ??= ['total' => 0, 'dentro' => 0, 'fuera' => 0];

            $superaron = 0;
            $maxHoras = 0.0;
            foreach ($epEnPeriodo as $ep) {
                $horas = max(0.0, $ep['inicio']->diffInMinutes($ep['fin'], false) / 60.0);
                $cumple = $horas <= self::LIMITE_HORAS_DESBORDE;
                $totalEpisodios++;
                $sumaHoras += $horas;
                $maxHoras = max($maxHoras, $horas);
                $porSector[$sector]['total']++;
                if ($cumple) {
                    $dentroLimite++;
                    $porSector[$sector]['dentro']++;
                } else {
                    $superaron++;
                    $porSector[$sector]['fuera']++;
                }
                $episodiosLista[] = ['fecha' => $ep['fin'], 'cumple' => $cumple];
            }

            $esCronico = $superaron >= 3;
            $filaPunto = [
                'punto_id' => $punto->id,
                'nombre' => $punto->nombre,
                'sector' => $sector,
                'tipo' => $punto->tipo_punto,
                'episodios' => count($epEnPeriodo),
                'superaron_12h' => $superaron,
                'max_horas' => round($maxHoras, 1),
                'es_cronico' => $esCronico,
            ];
            $porPunto[] = $filaPunto;
            if ($esCronico) {
                $cronicos[] = $filaPunto;
            }
        }

        usort($porPunto, static fn (array $a, array $b): int => $b['superaron_12h'] <=> $a['superaron_12h'] ?: $b['max_horas'] <=> $a['max_horas']);

        return [
            'total_episodios' => $totalEpisodios,
            'dentro_limite' => $dentroLimite,
            'pct_dentro_limite' => $totalEpisodios > 0 ? round(($dentroLimite / $totalEpisodios) * 100, 1) : 0.0,
            'horas_promedio' => $totalEpisodios > 0 ? round($sumaHoras / $totalEpisodios, 1) : 0.0,
            'por_sector' => $porSector,
            'por_punto' => $porPunto,
            'cronicos' => $cronicos,
            'episodios_lista' => $episodiosLista,
        ];
    }

    /**
     * @param  Collection<int, Ruta>  $rutas
     * @return array<string, mixed>
     */
    private function calcularPlanificacion(Collection $rutas): array
    {
        $paradasTotales = 0;
        $paradasCompletadas = 0;
        $desviosEta = [];
        $desviosKm = [];
        $porRuta = [];

        foreach ($rutas as $ruta) {
            $tot = $ruta->paradas->count();
            if ($tot === 0) {
                continue;
            }
            $comp = $ruta->paradas->where('estado_parada', RutaPunto::ESTADO_RECOLECTADO)->count();
            $paradasTotales += $tot;
            $paradasCompletadas += $comp;

            $etasRuta = [];
            foreach ($ruta->paradas as $p) {
                if ($p->hora_estimada_llegada && $p->hora_real_recoleccion) {
                    $d = abs($p->hora_estimada_llegada->diffInMinutes($p->hora_real_recoleccion, false));
                    $etasRuta[] = $d;
                    $desviosEta[] = $d;
                }
            }
            $etaRutaProm = $etasRuta !== [] ? array_sum($etasRuta) / count($etasRuta) : 0.0;

            $kmEst = (float) $ruta->distancia_estimada_km;
            $kmReal = (float) $ruta->distancia_real_km;
            $desvioKmRuta = ($kmEst > 0 && $kmReal > 0) ? (abs($kmReal - $kmEst) / $kmEst) * 100.0 : null;
            if ($desvioKmRuta !== null) {
                $desviosKm[] = $desvioKmRuta;
            }

            $indiceRuta = $this->combinarIndicePlanificacion(
                ($comp / $tot) * 100.0,
                $etasRuta !== [] ? max(0.0, 100.0 - $etaRutaProm * 4.0) : null,
                $desvioKmRuta !== null ? max(0.0, 100.0 - $desvioKmRuta) : null,
            );

            $porRuta[] = [
                'id' => $ruta->id,
                'fecha' => $ruta->fecha_programada->format('d-m-Y'),
                'patente' => $ruta->camion->patente,
                'completadas' => $comp,
                'total' => $tot,
                'desvio_eta' => round($etaRutaProm, 1),
                'km_real' => $kmReal,
                'km_est' => $kmEst,
                'indice' => $indiceRuta,
            ];
        }

        $paradasPct = $paradasTotales > 0 ? round(($paradasCompletadas / $paradasTotales) * 100, 1) : 0.0;
        $etaGlobal = $desviosEta !== [] ? round(array_sum($desviosEta) / count($desviosEta), 1) : 0.0;
        $kmGlobal = $desviosKm !== [] ? round(array_sum($desviosKm) / count($desviosKm), 1) : 0.0;

        $indice = $this->combinarIndicePlanificacion(
            $paradasPct,
            $desviosEta !== [] ? max(0.0, 100.0 - $etaGlobal * 4.0) : null,
            $desviosKm !== [] ? max(0.0, 100.0 - $kmGlobal) : null,
        );

        return [
            'paradas_totales' => $paradasTotales,
            'paradas_pct' => $paradasPct,
            'desvio_eta_min' => $etaGlobal,
            'desvio_km_pct' => $kmGlobal,
            'indice' => $indice,
            'por_ruta' => $porRuta,
        ];
    }

    private function combinarIndicePlanificacion(float $paradasPct, ?float $puntualidadPct, ?float $kmPct): float
    {
        $pesos = 0.40;
        $suma = $paradasPct * 0.40;
        if ($puntualidadPct !== null) {
            $suma += $puntualidadPct * 0.30;
            $pesos += 0.30;
        }
        if ($kmPct !== null) {
            $suma += $kmPct * 0.30;
            $pesos += 0.30;
        }

        return round($suma / $pesos, 1);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, array<string, mixed>>  $params
     * @return array<string, mixed>
     */
    private function calcularCostoConduccion(array $ctx, array $params): array
    {
        $precioComb = (float) $params[ParametroOperativo::PRECIO_COMBUSTIBLE_LITRO]['valor'];
        $rendRef = (float) $params[ParametroOperativo::RENDIMIENTO_REFERENCIA_KM_L]['valor'];
        $limiteVel = (float) $params[ParametroOperativo::LIMITE_VELOCIDAD_KMH]['valor'];

        $rutasConGps = 0;
        $kmExtraTotal = 0.0;
        $pingsExcesoTotal = 0;
        $litrosExtraTotal = 0.0;
        $pesosExtraTotal = 0.0;
        $usoRendRef = false;
        $porChofer = [];
        $porRuta = [];

        foreach ($ctx['rutas'] as $ruta) {
            $kmReal = (float) $ruta->distancia_real_km;
            /** @var Collection<int, TelemetriaGps> $pings */
            $pings = $ctx['pings_por_ruta']->get($ruta->id) ?? collect();

            if ($kmReal <= 0 && $pings->isEmpty()) {
                continue;
            }
            $rutasConGps++;

            $camion = $ruta->camion;
            $rend = ($camion && $camion->rendimiento_km_l !== null) ? (float) $camion->rendimiento_km_l : $rendRef;
            if (! $camion || $camion->rendimiento_km_l === null) {
                $usoRendRef = true;
            }

            $kmEst = (float) $ruta->distancia_estimada_km;
            $kmExtra = $kmReal > 0 && $kmEst > 0 ? max(0.0, $kmReal - $kmEst) : 0.0;

            $pingsExceso = $pings->where('velocidad_kmh', '>', $limiteVel)->count();
            $ratioExceso = $pings->isNotEmpty() ? $pingsExceso / $pings->count() : 0.0;

            $litrosDesvio = $kmExtra / max(0.1, $rend);
            $litrosVelocidad = (($kmReal > 0 ? $kmReal : $kmEst) * $ratioExceso / max(0.1, $rend)) * self::PENALIZACION_EXCESO_VELOCIDAD;
            $litros = round($litrosDesvio + $litrosVelocidad, 2);
            $pesos = round($litros * $precioComb, 0);

            $kmExtraTotal += $kmExtra;
            $pingsExcesoTotal += $pingsExceso;
            $litrosExtraTotal += $litros;
            $pesosExtraTotal += $pesos;

            $choferNombre = $ruta->chofer->nombre_cifrado;
            $choferKey = $ruta->chofer_id ?? 0;
            $porChofer[$choferKey] ??= [
                'nombre' => $choferNombre,
                'km_extra' => 0.0,
                'pings_exceso' => 0,
                'litros_extra' => 0.0,
                'pesos_extra' => 0.0,
            ];
            $porChofer[$choferKey]['km_extra'] += $kmExtra;
            $porChofer[$choferKey]['pings_exceso'] += $pingsExceso;
            $porChofer[$choferKey]['litros_extra'] = round($porChofer[$choferKey]['litros_extra'] + $litros, 2);
            $porChofer[$choferKey]['pesos_extra'] = round($porChofer[$choferKey]['pesos_extra'] + $pesos, 0);

            $porRuta[] = [
                'ruta_id' => $ruta->id,
                'fecha' => $ruta->fecha_programada->format('Y-m-d'),
                'chofer' => $choferNombre,
                'km_extra' => round($kmExtra, 2),
                'pings_exceso' => $pingsExceso,
                'litros_extra' => $litros,
                'pesos_extra' => $pesos,
            ];
        }

        return [
            'rutas_con_gps' => $rutasConGps,
            'km_extra' => round($kmExtraTotal, 2),
            'pings_exceso' => $pingsExcesoTotal,
            'litros_extra' => round($litrosExtraTotal, 2),
            'pesos_extra' => round($pesosExtraTotal, 0),
            'uso_rendimiento_referencia' => $usoRendRef,
            'por_chofer' => array_values($porChofer),
            'por_ruta' => $porRuta,
        ];
    }

    /**
     * Evalúa bajo demanda proyecciones de días ya cumplidos contra los puntos
     * que realmente alcanzaron el umbral ese día (sin depender de cron).
     */
    private function evaluarProyeccionesPendientes(float $umbral): void
    {
        $pendientes = ProyeccionFlota::query()
            ->whereNull('puntos_reales_listos')
            ->whereDate('fecha_objetivo', '<=', DiaDeNegocio::hoy()->format('Y-m-d'))
            ->get();

        foreach ($pendientes as $proy) {
            $fecha = $proy->fecha_objetivo;
            $inicio = DiaDeNegocio::inicioDelDia($fecha);
            $fin = DiaDeNegocio::finDelDia($fecha);

            $idsPorLectura = LecturaPunto::query()
                ->whereBetween('medido_en', [$inicio, $fin])
                ->where('porcentaje_llenado', '>=', (int) round($umbral))
                ->distinct()
                ->pluck('punto_id')
                ->all();

            $idsPorRuta = RutaPunto::query()
                ->whereHas('ruta', static fn ($q) => $q->whereDate('fecha_programada', $fecha->format('Y-m-d')))
                ->distinct()
                ->pluck('punto_id')
                ->all();

            $idsUnicos = array_unique(array_merge($idsPorLectura, $idsPorRuta));

            if ($idsUnicos === [] && $fecha->isToday()) {
                $idsUnicos = Punto::query()
                    ->where('activo', true)
                    ->where('porcentaje_llenado', '>=', (int) round($umbral))
                    ->pluck('id')
                    ->all();
            }

            $reales = count($idsUnicos);
            $proyectados = (int) $proy->puntos_proyectados;
            $base = max(1, $reales, $proyectados);
            $acierto = ($reales === 0 && $proyectados === 0)
                ? 100.0
                : max(0.0, round((1.0 - abs($proyectados - $reales) / $base) * 100.0, 1));

            $proy->update([
                'puntos_reales_listos' => $reales,
                'porcentaje_acierto' => $acierto,
                'evaluada_en' => now(),
            ]);
        }
    }

    /**
     * @param  Collection<int, LecturaPunto>  $lecturas
     */
    private function velocidadLlenadoPunto(Collection $lecturas): ?float
    {
        if ($lecturas->count() < 2) {
            return null;
        }
        $tasas = [];
        $prev = null;
        foreach ($lecturas as $l) {
            if ($prev instanceof LecturaPunto && $l->medido_en && $prev->medido_en) {
                $dtHoras = $prev->medido_en->diffInMinutes($l->medido_en, false) / 60.0;
                $delta = (int) $l->porcentaje_llenado - (int) $prev->porcentaje_llenado;
                if ($dtHoras >= 1.0 && $delta >= 0) {
                    $tasas[] = $delta / ($dtHoras / 24.0);
                }
            }
            $prev = $l;
        }

        return $tasas !== [] ? array_sum($tasas) / count($tasas) : null;
    }

    private function kmDeRuta(Ruta $ruta): float
    {
        $real = (float) $ruta->distancia_real_km;

        return $real > 0 ? $real : (float) $ruta->distancia_estimada_km;
    }

    private function horasDeRuta(Ruta $ruta): float
    {
        if ($ruta->hora_inicio_real && $ruta->hora_termino_real) {
            $min = max(1, $ruta->hora_inicio_real->diffInMinutes($ruta->hora_termino_real, false));

            return $min / 60.0;
        }

        return max(0.1, ((float) $ruta->duracion_estimada_min) / 60.0);
    }

    private function sectorDominanteRuta(Ruta $ruta): string
    {
        $sectores = [];
        foreach ($ruta->paradas as $p) {
            if ($p->punto instanceof Punto) {
                $sectores[] = $p->punto->sectorResuelto();
            }
        }
        if ($sectores === []) {
            return Punto::SIN_SECTOR;
        }
        $conteos = array_count_values($sectores);
        arsort($conteos);

        return (string) array_key_first($conteos);
    }

    /**
     * @param  list<float>  $valores
     */
    private function mediana(array $valores): float
    {
        sort($valores);
        $n = count($valores);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);

        return $n % 2 === 0
            ? ($valores[$mid - 1] + $valores[$mid]) / 2.0
            : $valores[$mid];
    }

    private function normalizarContraMediana(float $valor, float $mediana): float
    {
        if ($mediana <= 0.001) {
            return min(100.0, max(0.0, $valor));
        }

        return min(100.0, max(0.0, ($valor / $mediana) * 85.0));
    }

    private function calcularTendencia(float $actual, ?float $previo): ?float
    {
        if ($previo === null || abs($previo) < 0.001) {
            return null;
        }

        return round((($actual - $previo) / abs($previo)) * 100.0, 1);
    }

    private function textoTendencia(?float $tendencia, bool $menorEsMejor): string
    {
        if ($tendencia === null) {
            return '';
        }
        if (abs($tendencia) < 0.1) {
            return ', igual que el período anterior';
        }
        $dir = $tendencia > 0 ? 'más' : 'menos';
        $calif = ($menorEsMejor ? $tendencia < 0 : $tendencia > 0) ? ' (mejora)' : '';

        return sprintf(', %s %% %s que el período anterior%s', number_format(abs($tendencia), 1, ',', '.'), $dir, $calif);
    }

    private function formatoPesos(float $valor): string
    {
        return '$'.number_format(round($valor), 0, ',', '.');
    }

    private function claveCubo(CarbonInterface $fecha, string $agrupacion): string
    {
        return match ($agrupacion) {
            'dia' => $fecha->format('d-m'),
            'mes' => $fecha->format('m-Y'),
            default => 'Sem '.$fecha->format('W'),
        };
    }

    /**
     * @param  Collection<int, Ruta>  $rutas
     * @return list<array{etiqueta: string, valor: float}>
     */
    private function serieKmPorCubo(Collection $rutas, string $agrupacion): array
    {
        $cubos = [];
        foreach ($rutas as $r) {
            $k = $this->claveCubo($r->fecha_programada, $agrupacion);
            $cubos[$k] = round(($cubos[$k] ?? 0.0) + $this->kmDeRuta($r), 1);
        }

        return $this->mapearSerie($cubos);
    }

    /**
     * @param  Collection<int, Ruta>  $rutas
     * @return list<array{etiqueta: string, valor: float}>
     */
    private function seriePuntosVaciadosPorCubo(Collection $rutas, string $agrupacion): array
    {
        $cubos = [];
        foreach ($rutas as $r) {
            $k = $this->claveCubo($r->fecha_programada, $agrupacion);
            $pts = $r->paradas->where('estado_parada', RutaPunto::ESTADO_RECOLECTADO)->count();
            $cubos[$k] = ($cubos[$k] ?? 0.0) + $pts;
        }

        return $this->mapearSerie($cubos);
    }

    /**
     * @param  Collection<int, Ruta>  $rutas
     * @return list<array{etiqueta: string, valor: float}>
     */
    private function serieExcluidosPorCubo(Collection $rutas, string $agrupacion): array
    {
        $cubos = [];
        foreach ($rutas as $r) {
            $k = $this->claveCubo($r->fecha_programada, $agrupacion);
            $cubos[$k] = ($cubos[$k] ?? 0.0) + (int) $r->puntos_excluidos_count;
        }

        return $this->mapearSerie($cubos);
    }

    /**
     * @param  Collection<int, Ruta>  $rutas
     * @return list<array{etiqueta: string, valor: float}>
     */
    private function serieHorasRutaPorCubo(Collection $rutas, string $agrupacion): array
    {
        $cubos = [];
        foreach ($rutas as $r) {
            $k = $this->claveCubo($r->fecha_programada, $agrupacion);
            $cubos[$k] = round(($cubos[$k] ?? 0.0) + $this->horasDeRuta($r), 1);
        }

        // KPI-03 se expresa en %, pero esta serie son horas de ruta.
        return array_map(
            static fn (array $item): array => $item + ['unidad' => 'h'],
            $this->mapearSerie($cubos),
        );
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, array<string, mixed>>  $params
     * @return list<array{etiqueta: string, valor: float}>
     */
    private function serieCostoPorPunto(array $ctx, array $params, string $agrupacion): array
    {
        $precioComb = (float) $params[ParametroOperativo::PRECIO_COMBUSTIBLE_LITRO]['valor'];
        $rendRef = (float) $params[ParametroOperativo::RENDIMIENTO_REFERENCIA_KM_L]['valor'];
        $arriendoRef = (float) $params[ParametroOperativo::ARRIENDO_REFERENCIA_DIARIO]['valor'];
        $cubos = [];

        foreach ($ctx['rutas'] as $r) {
            $k = $this->claveCubo($r->fecha_programada, $agrupacion);
            $rend = ($r->camion && $r->camion->rendimiento_km_l !== null) ? (float) $r->camion->rendimiento_km_l : $rendRef;
            $arr = ($r->camion && $r->camion->costo_arriendo_diario !== null) ? (float) $r->camion->costo_arriendo_diario : $arriendoRef;
            $pts = $r->paradas->where('estado_parada', RutaPunto::ESTADO_RECOLECTADO)->count();
            $cubos[$k] ??= ['costo' => 0.0, 'pts' => 0];
            $cubos[$k]['costo'] += ($this->kmDeRuta($r) * $precioComb / max(0.1, $rend)) + $arr;
            $cubos[$k]['pts'] += $pts;
        }

        $res = [];
        foreach ($cubos as $k => $v) {
            if ($v['pts'] > 0) {
                $res[] = ['etiqueta' => $k, 'valor' => round($v['costo'] / $v['pts'], 0)];
            }
        }

        return $res;
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, array<string, mixed>>  $params
     * @return list<array{etiqueta: string, valor: float}>
     */
    private function serieAhorroPorCubo(array $ctx, array $params, string $agrupacion): array
    {
        $global = $this->calcularAhorro($ctx, $params);
        $cubos = [];
        foreach ($ctx['rutas'] as $r) {
            $k = $this->claveCubo($r->fecha_programada, $agrupacion);
            $ahorro = (int) $r->puntos_excluidos_count * $global['km_por_parada'] * $global['costo_por_km'];
            $cubos[$k] = round(($cubos[$k] ?? 0.0) + $ahorro, 0);
        }

        return $this->mapearSerie($cubos);
    }

    /**
     * @param  Collection<int, Ruta>  $rutas
     * @return list<array{etiqueta: string, valor: float}>
     */
    private function serieEfectividadPorCubo(Collection $rutas, float $umbral, string $agrupacion): array
    {
        $cubos = [];
        foreach ($rutas as $r) {
            $k = $this->claveCubo($r->fecha_programada, $agrupacion);
            foreach ($r->paradas as $p) {
                if ($p->estado_parada !== RutaPunto::ESTADO_RECOLECTADO || $p->porcentaje_llenado_al_vaciar === null) {
                    continue;
                }
                $cubos[$k] ??= ['ok' => 0, 'tot' => 0];
                $cubos[$k]['tot']++;
                if ($p->porcentaje_llenado_al_vaciar >= $umbral) {
                    $cubos[$k]['ok']++;
                }
            }
        }
        $res = [];
        foreach ($cubos as $k => $v) {
            $res[] = ['etiqueta' => $k, 'valor' => round(($v['ok'] / max(1, $v['tot'])) * 100, 1)];
        }

        return $res;
    }

    /**
     * @param  list<array{fecha: CarbonInterface, cumple: bool}>  $episodios
     * @return list<array{etiqueta: string, valor: float}>
     */
    private function serieDesbordesPorCubo(array $episodios, string $agrupacion): array
    {
        $cubos = [];
        foreach ($episodios as $ep) {
            $k = $this->claveCubo($ep['fecha'], $agrupacion);
            $cubos[$k] ??= ['ok' => 0, 'tot' => 0];
            $cubos[$k]['tot']++;
            if ($ep['cumple']) {
                $cubos[$k]['ok']++;
            }
        }
        $res = [];
        foreach ($cubos as $k => $v) {
            $res[] = ['etiqueta' => $k, 'valor' => round(($v['ok'] / max(1, $v['tot'])) * 100, 1)];
        }

        return $res;
    }

    /**
     * @param  Collection<int, Ruta>  $rutas
     * @return list<array{etiqueta: string, valor: float}>
     */
    private function seriePlanificacionPorCubo(Collection $rutas, string $agrupacion): array
    {
        $cubos = [];
        foreach ($rutas as $r) {
            $k = $this->claveCubo($r->fecha_programada, $agrupacion);
            $cubos[$k] ??= collect();
            $cubos[$k]->push($r);
        }
        $res = [];
        foreach ($cubos as $k => $lista) {
            $c = $this->calcularPlanificacion($lista);
            if ($c['paradas_totales'] > 0) {
                $res[] = ['etiqueta' => $k, 'valor' => $c['indice']];
            }
        }

        return $res;
    }

    /**
     * @param  list<array<string, mixed>>  $porRuta
     * @return list<array{etiqueta: string, valor: float}>
     */
    private function serieConduccionPorCubo(array $porRuta, string $agrupacion): array
    {
        $cubos = [];
        foreach ($porRuta as $r) {
            $k = $this->claveCubo(Carbon::parse((string) $r['fecha']), $agrupacion);
            $cubos[$k] = round(($cubos[$k] ?? 0.0) + (float) $r['pesos_extra'], 0);
        }

        return $this->mapearSerie($cubos);
    }

    /**
     * @param  array<string, float>  $cubos
     * @return list<array{etiqueta: string, valor: float}>
     */
    private function mapearSerie(array $cubos): array
    {
        $res = [];
        foreach ($cubos as $k => $v) {
            $res[] = ['etiqueta' => $k, 'valor' => round($v, 2)];
        }

        return $res;
    }

    /**
     * @param  list<string>  $vistas
     * @return array<string, mixed>
     */
    private function kpiSinDatos(
        string $id,
        string $categoria,
        string $titulo,
        string $meta,
        ?float $metaNumerica,
        string $unidad,
        string $comoSeCalcula,
        array $vistas,
        bool $esProyeccion = false,
    ): array {
        return [
            'id' => $id,
            'categoria' => $categoria,
            'titulo' => $titulo,
            'es_proyeccion' => $esProyeccion,
            'sin_datos_suficientes' => true,
            'mensaje_sin_datos' => self::MENSAJE_SIN_DATOS,
            'valor' => null,
            'unidad' => $unidad,
            'valor_formateado' => null,
            'meta' => $meta,
            'meta_numerica' => $metaNumerica,
            'semaforo' => 'sin_datos',
            'tendencia_pct' => null,
            'tendencia_positiva' => null,
            'interpretacion' => self::MENSAJE_SIN_DATOS,
            'como_se_calcula' => $comoSeCalcula,
            'vistas_disponibles' => $vistas,
            'series_linea' => [],
            'comparacion_barras' => [],
            'tabla' => ['columnas' => [], 'filas' => []],
            'metricas_usadas' => [],
            'extras' => [],
        ];
    }
}
