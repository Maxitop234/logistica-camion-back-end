<?php

namespace App\Services;

use App\Models\Camion;
use App\Models\Chofer;
use App\Models\Ruta;
use App\Models\TelemetriaGps;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GpsTelemetryService
{
    public const MAX_PRECISION_M = 50.0;

    public const MIN_SALTO_METROS = 5.0;

    public const MIN_SALTO_DETENIDO_METROS = 15.0;

    public const VELOCIDAD_DETENIDO_KMH = 2.0;

    public const MAX_VELOCIDAD_REALISTA_KMH = 140.0;

    public const UMBRAL_SENAL_EN_LINEA_SEG = 30;

    public const TOLERANCIA_RELOJ_SEG = 5;

    public const VIGENCIA_ORIGEN_HORAS = 24;

    /**
     * Registra uno o varios pings GPS para el chofer autenticado, respetando
     * el orden cronológico, la idempotencia por `ping_uuid`, la restricción de
     * modo demostración y la ventana temporal activa de la ruta (Ley 21.719).
     *
     * @param  list<array<string, mixed>>  $pings
     * @return array{
     *   aceptados: int,
     *   duplicados: int,
     *   rechazados: int,
     *   rutas: array<int, float>
     * }
     */
    public function registrarPings(Chofer $chofer, array $pings): array
    {
        $ordenados = $pings;
        usort($ordenados, static function (array $a, array $b): int {
            $ta = Carbon::parse((string) $a['capturado_en'])->getTimestampMs();
            $tb = Carbon::parse((string) $b['capturado_en'])->getTimestampMs();

            return $ta <=> $tb;
        });

        $aceptados = 0;
        $duplicados = 0;
        $rechazados = 0;

        /** @var array<int, Ruta> $rutasAfectadas */
        $rutasAfectadas = [];

        /** @var array<int, TelemetriaGps> $ultimoPingPorCamion */
        $ultimoPingPorCamion = [];

        DB::transaction(function () use (
            $chofer,
            $ordenados,
            &$aceptados,
            &$duplicados,
            &$rechazados,
            &$rutasAfectadas,
            &$ultimoPingPorCamion,
        ): void {
            foreach ($ordenados as $item) {
                $pingUuid = (string) $item['ping_uuid'];
                if (TelemetriaGps::where('ping_uuid', $pingUuid)->exists()) {
                    $duplicados++;

                    continue;
                }

                $capturadoEn = Carbon::parse((string) $item['capturado_en'])->utc();
                $rutaIdSolicitada = isset($item['ruta_id']) ? (int) $item['ruta_id'] : null;
                $ruta = $this->resolverRutaValidaParaPing($chofer, $capturadoEn, $rutaIdSolicitada);

                if (! $ruta instanceof Ruta) {
                    $rechazados++;

                    continue;
                }

                $ping = TelemetriaGps::create([
                    'ruta_id' => $ruta->id,
                    'camion_id' => $ruta->camion_id,
                    'chofer_id' => $chofer->id,
                    'ping_uuid' => $pingUuid,
                    'latitud' => (float) $item['latitud'],
                    'longitud' => (float) $item['longitud'],
                    'velocidad_kmh' => max(0.0, (float) ($item['velocidad_kmh'] ?? 0.0)),
                    'precision_m' => array_key_exists('precision_m', $item) && $item['precision_m'] !== null
                        ? (float) $item['precision_m']
                        : null,
                    'es_simulado' => (bool) ($item['simulado'] ?? false),
                    'capturado_en' => $capturadoEn,
                ]);

                $aceptados++;
                $rutasAfectadas[$ruta->id] = $ruta;

                $previo = $ultimoPingPorCamion[$ruta->camion_id] ?? null;
                if (! $previo instanceof TelemetriaGps || $ping->capturado_en->greaterThanOrEqualTo($previo->capturado_en)) {
                    $ultimoPingPorCamion[$ruta->camion_id] = $ping;
                }
            }

            foreach ($ultimoPingPorCamion as $camionId => $pingReciente) {
                $camion = Camion::find($camionId);
                if (! $camion instanceof Camion) {
                    continue;
                }

                if (
                    $camion->ultima_actualizacion_gps === null ||
                    $pingReciente->capturado_en->greaterThan($camion->ultima_actualizacion_gps)
                ) {
                    $camion->update([
                        'ultima_latitud' => $pingReciente->latitud,
                        'ultima_longitud' => $pingReciente->longitud,
                        'velocidad_actual_kmh' => $pingReciente->velocidad_kmh,
                        'ultima_actualizacion_gps' => $pingReciente->capturado_en,
                    ]);
                }
            }

            foreach ($rutasAfectadas as $ruta) {
                $this->recalcularDistanciaReal($ruta);
            }
        });

        $distancias = [];
        foreach ($rutasAfectadas as $rutaId => $ruta) {
            $distancias[$rutaId] = (float) $ruta->fresh()->distancia_real_km;
        }

        return [
            'aceptados' => $aceptados,
            'duplicados' => $duplicados,
            'rechazados' => $rechazados,
            'rutas' => $distancias,
        ];
    }

    /**
     * Origen de una ruta nueva: última posición GPS válida del chofer (precisión
     * aceptable, capturada en las últimas 24 h) o, si no existe, la Planta Terranova.
     *
     * @return array{0: float, 1: float} [latitud, longitud]
     */
    public function origenParaChofer(Chofer $chofer): array
    {
        $ultimo = TelemetriaGps::query()
            ->where('chofer_id', $chofer->id)
            ->where('capturado_en', '>=', now()->subHours(self::VIGENCIA_ORIGEN_HORAS))
            ->where(function ($q): void {
                $q->whereNull('precision_m')->orWhere('precision_m', '<=', self::MAX_PRECISION_M);
            })
            ->orderByDesc('capturado_en')
            ->orderByDesc('id')
            ->first();

        if ($ultimo instanceof TelemetriaGps) {
            return [(float) $ultimo->latitud, (float) $ultimo->longitud];
        }

        return [OsmRouteOptimizerService::PLANTA_LAT, OsmRouteOptimizerService::PLANTA_LNG];
    }

    /**
     * Recalcula y persiste `rutas.distancia_real_km` usando solo pings válidos
     * ordenados cronológicamente (descarta ruido GPS y saltos irreales).
     */
    public function recalcularDistanciaReal(Ruta $ruta): float
    {
        $pings = TelemetriaGps::query()
            ->where('ruta_id', $ruta->id)
            ->orderBy('capturado_en')
            ->orderBy('id')
            ->get();

        $totalMetros = 0.0;
        $anterior = null;

        foreach ($pings as $actual) {
            if ($actual->precision_m !== null && $actual->precision_m > self::MAX_PRECISION_M) {
                continue;
            }

            if (! $anterior instanceof TelemetriaGps) {
                $anterior = $actual;

                continue;
            }

            $metros = $this->haversineMetros(
                $anterior->latitud,
                $anterior->longitud,
                $actual->latitud,
                $actual->longitud,
            );

            if ($metros < self::MIN_SALTO_METROS) {
                continue;
            }

            if (
                $actual->velocidad_kmh < self::VELOCIDAD_DETENIDO_KMH &&
                $anterior->velocidad_kmh < self::VELOCIDAD_DETENIDO_KMH &&
                $metros < self::MIN_SALTO_DETENIDO_METROS
            ) {
                continue;
            }

            $dtSeg = max(1.0, abs($actual->capturado_en->diffInMilliseconds($anterior->capturado_en)) / 1000.0);
            $velocidadImplicitaKmh = ($metros / 1000.0) / ($dtSeg / 3600.0);

            if ($metros > 100.0 && $velocidadImplicitaKmh > self::MAX_VELOCIDAD_REALISTA_KMH) {
                continue;
            }

            $totalMetros += $metros;
            $anterior = $actual;
        }

        $distanciaKm = round($totalMetros / 1000.0, 2);
        $ruta->update(['distancia_real_km' => $distanciaKm]);

        return $distanciaKm;
    }

    /**
     * Determina el estado de la señal GPS de un camión según la antigüedad del
     * último ping recibido (`en_linea` ≤ 30 s, `atrasada` > 30 s, `sin_senal`).
     */
    public function estadoSenal(?CarbonInterface $ultimaActualizacion): string
    {
        if (! $ultimaActualizacion instanceof CarbonInterface) {
            return 'sin_senal';
        }

        return $ultimaActualizacion->greaterThanOrEqualTo(now()->subSeconds(self::UMBRAL_SENAL_EN_LINEA_SEG))
            ? 'en_linea'
            : 'atrasada';
    }

    /**
     * Distancia geodésica entre dos coordenadas usando la fórmula de Haversine.
     */
    public function haversineMetros(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $radioTierraMetros = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLng / 2) ** 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $radioTierraMetros * $c;
    }

    /**
     * Busca la ruta del chofer a la que pertenece legalmente el ping según su
     * `capturado_en`: permite pings de una ruta `en_curso` y también pings
     * rezagados de una ruta `completada` solo si fueron capturados entre su
     * `hora_inicio_real` y `hora_termino_real`.
     */
    private function resolverRutaValidaParaPing(Chofer $chofer, CarbonInterface $capturadoEn, ?int $rutaId): ?Ruta
    {
        if ($rutaId !== null) {
            $ruta = Ruta::query()
                ->where('id', $rutaId)
                ->where('chofer_id', $chofer->id)
                ->first();

            if ($ruta instanceof Ruta && $this->capturadoEnVentanaActiva($ruta, $capturadoEn)) {
                return $ruta;
            }

            return null;
        }

        $rutaEnCurso = Ruta::query()
            ->where('chofer_id', $chofer->id)
            ->where('estado', Ruta::ESTADO_EN_CURSO)
            ->latest('id')
            ->first();

        if ($rutaEnCurso instanceof Ruta && $this->capturadoEnVentanaActiva($rutaEnCurso, $capturadoEn)) {
            return $rutaEnCurso;
        }

        $candidatas = Ruta::query()
            ->where('chofer_id', $chofer->id)
            ->where('estado', Ruta::ESTADO_COMPLETADA)
            ->whereNotNull('hora_inicio_real')
            ->whereNotNull('hora_termino_real')
            ->latest('hora_termino_real')
            ->limit(5)
            ->get();

        foreach ($candidatas as $completada) {
            if ($this->capturadoEnVentanaActiva($completada, $capturadoEn)) {
                return $completada;
            }
        }

        return null;
    }

    private function capturadoEnVentanaActiva(Ruta $ruta, CarbonInterface $capturadoEn): bool
    {
        if ($ruta->estado === Ruta::ESTADO_EN_CURSO) {
            if ($ruta->hora_inicio_real instanceof CarbonInterface) {
                $minimo = $ruta->hora_inicio_real->toMutable()->utc()->subSeconds(self::TOLERANCIA_RELOJ_SEG);
                if ($capturadoEn->lessThan($minimo)) {
                    return false;
                }
            }

            return $capturadoEn->lessThanOrEqualTo(now()->utc()->addMinutes(2));
        }

        if (
            $ruta->estado === Ruta::ESTADO_COMPLETADA &&
            $ruta->hora_inicio_real instanceof CarbonInterface &&
            $ruta->hora_termino_real instanceof CarbonInterface
        ) {
            $inicio = $ruta->hora_inicio_real->toMutable()->utc()->subSeconds(self::TOLERANCIA_RELOJ_SEG);
            $termino = $ruta->hora_termino_real->toMutable()->utc()->addSeconds(self::TOLERANCIA_RELOJ_SEG);

            return $capturadoEn->betweenIncluded($inicio, $termino);
        }

        return false;
    }
}
