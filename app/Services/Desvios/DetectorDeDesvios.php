<?php

namespace App\Services\Desvios;

use App\Models\DesvioRuta;
use App\Models\Ruta;
use App\Models\TelemetriaGps;
use App\Services\GpsTelemetryService;
use App\Services\OsmRouteOptimizerService;

/**
 * Compara la traza GPS real de una ruta con su recorrido planificado y guarda
 * los tramos de desvío (más de `umbral_m` de la ruta durante al menos
 * `longitud_minima_m`). Es idempotente: un mismo desvío se identifica por
 * (ruta, instante de inicio) y conserva el motivo que informó el chofer.
 */
class DetectorDeDesvios
{
    private const RADIO_TIERRA_M = 6371000.0;

    /** Lado de la celda (en grados, ~110 m) para agrupar desvíos sobre el mismo tramo. */
    private const CELDA_GRADOS = 0.001;

    /**
     * @return list<DesvioRuta>
     */
    public function detectar(Ruta $ruta): array
    {
        $codificada = (string) $ruta->encoded_polyline;
        $planificada = $codificada === '' ? [] : OsmRouteOptimizerService::decodificarPolilinea($codificada);
        if (count($planificada) < 2) {
            return [];
        }
        $acum = $this->acumulado($planificada);

        $umbral = (float) config('ecoruta.desvios.umbral_m');
        $largoMinimo = (float) config('ecoruta.desvios.longitud_minima_m');

        $pings = TelemetriaGps::query()
            ->where('ruta_id', $ruta->id)
            ->where(function ($q): void {
                $q->whereNull('precision_m')->orWhere('precision_m', '<=', GpsTelemetryService::MAX_PRECISION_M);
            })
            ->orderBy('capturado_en')
            ->orderBy('id')
            ->get();

        $guardados = [];
        /** @var list<array{ping: TelemetriaGps, proy: array{distancia: float, progreso: float}}> $corrida */
        $corrida = [];
        $anterior = null;

        foreach ($pings as $ping) {
            $proy = $this->proyectar($planificada, $acum, $ping->latitud, $ping->longitud);
            $actual = ['ping' => $ping, 'proy' => $proy];
            if ($proy['distancia'] > $umbral) {
                $corrida[] = $actual;

                continue;
            }
            if ($corrida !== []) {
                $d = $this->construirDesvio($ruta, $planificada, $acum, $anterior, $corrida, $actual, $largoMinimo);
                if ($d instanceof DesvioRuta) {
                    $guardados[] = $d;
                }
                $corrida = [];
            }
            $anterior = $actual;
        }
        // Corrida abierta (ruta en curso o terminada fuera de la ruta planificada).
        if ($corrida !== []) {
            $d = $this->construirDesvio($ruta, $planificada, $acum, $anterior, $corrida, null, $largoMinimo);
            if ($d instanceof DesvioRuta) {
                $guardados[] = $d;
            }
        }

        return $guardados;
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $planificada
     * @param  list<float>  $acum
     * @param  array{ping: TelemetriaGps, proy: array{distancia: float, progreso: float}}|null  $anterior
     * @param  list<array{ping: TelemetriaGps, proy: array{distancia: float, progreso: float}}>  $corrida
     * @param  array{ping: TelemetriaGps, proy: array{distancia: float, progreso: float}}|null  $posterior
     */
    private function construirDesvio(
        Ruta $ruta,
        array $planificada,
        array $acum,
        ?array $anterior,
        array $corrida,
        ?array $posterior,
        float $largoMinimo,
    ): ?DesvioRuta {
        $tramo = [];
        if ($anterior !== null) {
            $tramo[] = $anterior['ping'];
        }
        foreach ($corrida as $c) {
            $tramo[] = $c['ping'];
        }
        if ($posterior !== null) {
            $tramo[] = $posterior['ping'];
        }

        $largo = $this->largoTrazado($tramo);
        // La longitud mínima se mide solo entre las lecturas fuera de ruta (sin los anclajes).
        $largoFuera = $this->largoTrazado(array_map(static fn (array $c): TelemetriaGps => $c['ping'], $corrida));
        if ($largoFuera < $largoMinimo) {
            return null;
        }

        $primero = $corrida[0]['ping'];
        $ultimo = $corrida[count($corrida) - 1]['ping'];
        $maxSeparacion = 0.0;
        foreach ($corrida as $c) {
            $maxSeparacion = max($maxSeparacion, $c['proy']['distancia']);
        }

        $progresoInicio = ($anterior ?? $corrida[0])['proy']['progreso'];
        $progresoFin = ($posterior ?? $corrida[count($corrida) - 1])['proy']['progreso'];
        $desde = min($progresoInicio, $progresoFin);
        $hasta = max($progresoInicio, $progresoFin);
        $geomPlanificada = $this->recortar($planificada, $acum, $desde, max($hasta, $desde + 30));

        $puntoInicio = $this->puntoEn($planificada, $acum, $desde);
        $puntoFin = $this->puntoEn($planificada, $acum, $hasta);
        $clave = $this->celda($puntoInicio).'|'.$this->celda($puntoFin);

        $geomReal = OsmRouteOptimizerService::codificarPolilinea(array_map(
            static fn (TelemetriaGps $p): array => [$p->latitud, $p->longitud],
            $tramo,
        ));

        $existente = DesvioRuta::where('ruta_id', $ruta->id)->where('inicio_en', $primero->capturado_en)->first();
        $datos = [
            'chofer_id' => $ruta->chofer_id,
            'camion_id' => $ruta->camion_id,
            'fin_en' => $ultimo->capturado_en,
            'distancia_m' => (int) round($largo),
            'max_separacion_m' => (int) round($maxSeparacion),
            'geometria_real' => $geomReal,
            'geometria_planificada' => OsmRouteOptimizerService::codificarPolilinea($geomPlanificada),
            'clave_tramo' => $clave,
            'centro_latitud' => $primero->latitud,
            'centro_longitud' => $primero->longitud,
        ];

        if ($existente instanceof DesvioRuta) {
            $existente->update($datos);

            return $existente;
        }

        return DesvioRuta::create($datos + [
            'ruta_id' => $ruta->id,
            'inicio_en' => $primero->capturado_en,
        ]);
    }

    /**
     * @param  list<TelemetriaGps>  $pings
     */
    private function largoTrazado(array $pings): float
    {
        $largo = 0.0;
        for ($i = 1; $i < count($pings); $i++) {
            $largo += $this->haversine($pings[$i - 1]->latitud, $pings[$i - 1]->longitud, $pings[$i]->latitud, $pings[$i]->longitud);
        }

        return $largo;
    }

    /**
     * @param  array{0: float, 1: float}  $p
     */
    private function celda(array $p): string
    {
        return sprintf('%d,%d', (int) floor($p[0] / self::CELDA_GRADOS), (int) floor($p[1] / self::CELDA_GRADOS));
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $linea
     * @return list<float>
     */
    private function acumulado(array $linea): array
    {
        $acum = [0.0];
        for ($i = 1; $i < count($linea); $i++) {
            $acum[] = $acum[$i - 1] + $this->haversine($linea[$i - 1][0], $linea[$i - 1][1], $linea[$i][0], $linea[$i][1]);
        }

        return $acum;
    }

    /**
     * Punto más cercano de la línea: distancia (m) y metros recorridos sobre ella.
     *
     * @param  array<int, array{0: float, 1: float}>  $linea
     * @param  list<float>  $acum
     * @return array{distancia: float, progreso: float}
     */
    private function proyectar(array $linea, array $acum, float $lat, float $lng): array
    {
        $cosLat = cos(deg2rad($lat));
        $x = static fn (float $lo): float => deg2rad($lo - $lng) * $cosLat * self::RADIO_TIERRA_M;
        $y = static fn (float $la): float => deg2rad($la - $lat) * self::RADIO_TIERRA_M;

        $mejorD = INF;
        $mejorProgreso = 0.0;
        for ($i = 0; $i < count($linea) - 1; $i++) {
            $ax = $x($linea[$i][1]);
            $ay = $y($linea[$i][0]);
            $dx = $x($linea[$i + 1][1]) - $ax;
            $dy = $y($linea[$i + 1][0]) - $ay;
            $largo2 = $dx * $dx + $dy * $dy;
            $t = $largo2 == 0.0 ? 0.0 : max(0.0, min(1.0, (-$ax * $dx - $ay * $dy) / $largo2));
            $px = $ax + $dx * $t;
            $py = $ay + $dy * $t;
            $d = sqrt($px * $px + $py * $py);
            if ($d < $mejorD) {
                $mejorD = $d;
                $mejorProgreso = $acum[$i] + ($acum[$i + 1] - $acum[$i]) * $t;
            }
        }

        return ['distancia' => $mejorD, 'progreso' => $mejorProgreso];
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $linea
     * @param  list<float>  $acum
     * @return array{0: float, 1: float}
     */
    private function puntoEn(array $linea, array $acum, float $progreso): array
    {
        $n = count($linea);
        if ($progreso <= 0) {
            return $linea[0];
        }
        if ($progreso >= $acum[$n - 1]) {
            return $linea[$n - 1];
        }
        $i = 0;
        while ($i < $n - 2 && $acum[$i + 1] < $progreso) {
            $i++;
        }
        $largo = $acum[$i + 1] - $acum[$i];
        $t = $largo == 0.0 ? 0.0 : ($progreso - $acum[$i]) / $largo;

        return [
            $linea[$i][0] + ($linea[$i + 1][0] - $linea[$i][0]) * $t,
            $linea[$i][1] + ($linea[$i + 1][1] - $linea[$i][1]) * $t,
        ];
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $linea
     * @param  list<float>  $acum
     * @return list<array{0: float, 1: float}>
     */
    private function recortar(array $linea, array $acum, float $desde, float $hasta): array
    {
        $r = [$this->puntoEn($linea, $acum, $desde)];
        foreach ($linea as $i => $p) {
            if ($acum[$i] > $desde && $acum[$i] < $hasta) {
                $r[] = $p;
            }
        }
        $r[] = $this->puntoEn($linea, $acum, $hasta);

        return $r;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::RADIO_TIERRA_M * asin(sqrt(min(1.0, $a)));
    }
}
