<?php

namespace App\Services;

use App\Models\Punto;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

class OsmRouteOptimizerService
{
    public const PLANTA_LAT = -38.7655;

    public const PLANTA_LNG = -72.5865;

    private const MINUTOS_POR_PARADA = 4;

    private const VELOCIDAD_URBANA_KMH = 28.0;

    private const FACTOR_CALLES_LOCAL = 1.25;

    /**
     * Optimiza el orden de visita desde la planta (y regreso a planta) usando
     * OSRM sobre grafo real de OpenStreetMap (sentido de calles, distancia y ETA),
     * con respaldo local automático si OSRM no responde o excede el timeout.
     *
     * @param  Collection<int, Punto>  $puntos
     * @return array{
     *     paradas: array<int, array{
     *         punto: Punto,
     *         orden: int,
     *         distancia_tramo_km: float,
     *         duracion_tramo_min: int,
     *         hora_estimada_llegada: CarbonInterface
     *     }>,
     *     distancia_km: float,
     *     duracion_min: int,
     *     encoded_polyline: string,
     *     fuente: string
     * }
     */
    public function optimizar(
        Collection $puntos,
        float $origenLat = self::PLANTA_LAT,
        float $origenLng = self::PLANTA_LNG,
        ?CarbonInterface $horaSalida = null,
    ): array {
        $salida = ($horaSalida ?? now())->copy();
        $lista = $puntos->values();

        if ($lista->isEmpty()) {
            return [
                'paradas' => [],
                'distancia_km' => 0.0,
                'duracion_min' => 0,
                'encoded_polyline' => '',
                'fuente' => 'local',
            ];
        }

        $osrm = $this->intentarOsrm($lista, $origenLat, $origenLng, $salida);
        if ($osrm !== null) {
            return $osrm;
        }

        return $this->respaldoLocal($lista, $origenLat, $origenLng, $salida);
    }

    /**
     * @param  Collection<int, Punto>  $puntos
     * @return array{
     *     paradas: array<int, array{
     *         punto: Punto,
     *         orden: int,
     *         distancia_tramo_km: float,
     *         duracion_tramo_min: int,
     *         hora_estimada_llegada: CarbonInterface
     *     }>,
     *     distancia_km: float,
     *     duracion_min: int,
     *     encoded_polyline: string,
     *     fuente: string
     * }|null
     */
    private function intentarOsrm(
        Collection $puntos,
        float $origenLat,
        float $origenLng,
        CarbonInterface $salida,
    ): ?array {
        $baseUrl = rtrim((string) config('services.osrm.base_url', 'https://router.project-osrm.org'), '/');
        $timeout = max(2, (int) config('services.osrm.timeout', 6));

        $coordenadas = [sprintf('%.6f,%.6f', $origenLng, $origenLat)];
        foreach ($puntos as $p) {
            $coordenadas[] = sprintf('%.6f,%.6f', (float) $p->longitud, (float) $p->latitud);
        }

        $url = $baseUrl.'/trip/v1/driving/'.implode(';', $coordenadas);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders(['User-Agent' => 'EcoRutaTemuco/1.0'])
                ->get($url, [
                    'source' => 'first',
                    'roundtrip' => 'true',
                    'overview' => 'full',
                    'geometries' => 'polyline',
                    'steps' => 'false',
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json) || ($json['code'] ?? null) !== 'Ok') {
            return null;
        }

        $trips = $json['trips'] ?? null;
        $waypoints = $json['waypoints'] ?? null;
        if (! is_array($trips) || $trips === [] || ! is_array($waypoints) || count($waypoints) !== $puntos->count() + 1) {
            return null;
        }

        $trip = $trips[0];
        $geometry = $trip['geometry'] ?? null;
        $legs = $trip['legs'] ?? null;
        if (! is_string($geometry) || $geometry === '' || ! is_array($legs) || count($legs) < $puntos->count()) {
            return null;
        }

        // Mapea cada punto de entrada (índices 1..N) a su posición visitada en el viaje.
        $ordenados = [];
        for ($i = 1; $i <= $puntos->count(); $i++) {
            $wpIndex = $waypoints[$i]['waypoint_index'] ?? null;
            if (! is_int($wpIndex) || $wpIndex < 1 || $wpIndex > $puntos->count()) {
                return null;
            }
            $ordenados[$wpIndex] = $puntos[$i - 1];
        }

        if (count($ordenados) !== $puntos->count()) {
            return null;
        }

        ksort($ordenados);

        $paradas = [];
        $reloj = $salida->copy();
        $indiceLeg = 0;

        foreach ($ordenados as $orden => $punto) {
            $leg = $legs[$indiceLeg] ?? [];
            $distKm = round(((float) ($leg['distance'] ?? 0)) / 1000, 2);
            $durMin = max(1, (int) round(((float) ($leg['duration'] ?? 60)) / 60));

            $reloj = $reloj->copy()->addMinutes($durMin);
            $paradas[] = [
                'punto' => $punto,
                'orden' => $orden,
                'distancia_tramo_km' => $distKm,
                'duracion_tramo_min' => $durMin,
                'hora_estimada_llegada' => $reloj->copy(),
            ];
            $reloj = $reloj->copy()->addMinutes(self::MINUTOS_POR_PARADA);
            $indiceLeg++;
        }

        $distanciaTotalKm = round(((float) ($trip['distance'] ?? 0)) / 1000, 2);
        $duracionConduccionMin = (int) round(((float) ($trip['duration'] ?? 0)) / 60);
        $duracionTotalMin = max(1, $duracionConduccionMin + ($puntos->count() * self::MINUTOS_POR_PARADA));

        return [
            'paradas' => $paradas,
            'distancia_km' => $distanciaTotalKm,
            'duracion_min' => $duracionTotalMin,
            'encoded_polyline' => $geometry,
            'fuente' => 'osrm',
        ];
    }

    /**
     * Respaldo local (vecino más cercano con distancia Haversine ajustada)
     * cuando OSRM no responde.
     *
     * @param  Collection<int, Punto>  $puntos
     * @return array{
     *     paradas: array<int, array{
     *         punto: Punto,
     *         orden: int,
     *         distancia_tramo_km: float,
     *         duracion_tramo_min: int,
     *         hora_estimada_llegada: CarbonInterface
     *     }>,
     *     distancia_km: float,
     *     duracion_min: int,
     *     encoded_polyline: string,
     *     fuente: string
     * }
     */
    private function respaldoLocal(
        Collection $puntos,
        float $origenLat,
        float $origenLng,
        CarbonInterface $salida,
    ): array {
        $restantes = $puntos->all();
        $actualLat = $origenLat;
        $actualLng = $origenLng;
        $reloj = $salida->copy();
        $paradas = [];
        $trazado = [[$origenLat, $origenLng]];
        $distanciaTotal = 0.0;
        $duracionTotal = 0;
        $orden = 1;

        while ($restantes !== []) {
            $mejorIndice = 0;
            $mejorDistancia = PHP_FLOAT_MAX;

            foreach ($restantes as $idx => $candidato) {
                $d = $this->haversineKm($actualLat, $actualLng, (float) $candidato->latitud, (float) $candidato->longitud);
                if ($d < $mejorDistancia) {
                    $mejorDistancia = $d;
                    $mejorIndice = $idx;
                }
            }

            /** @var Punto $elegido */
            $elegido = $restantes[$mejorIndice];
            array_splice($restantes, $mejorIndice, 1);

            $tramoKm = round($mejorDistancia * self::FACTOR_CALLES_LOCAL, 2);
            $tramoMin = max(1, (int) round(($tramoKm / self::VELOCIDAD_URBANA_KMH) * 60));

            $reloj = $reloj->copy()->addMinutes($tramoMin);
            $paradas[] = [
                'punto' => $elegido,
                'orden' => $orden,
                'distancia_tramo_km' => $tramoKm,
                'duracion_tramo_min' => $tramoMin,
                'hora_estimada_llegada' => $reloj->copy(),
            ];

            $reloj = $reloj->copy()->addMinutes(self::MINUTOS_POR_PARADA);
            $distanciaTotal += $tramoKm;
            $duracionTotal += $tramoMin + self::MINUTOS_POR_PARADA;

            $actualLat = (float) $elegido->latitud;
            $actualLng = (float) $elegido->longitud;
            $trazado[] = [$actualLat, $actualLng];
            $orden++;
        }

        // Tramo de regreso a la planta.
        $regresoKm = round($this->haversineKm($actualLat, $actualLng, $origenLat, $origenLng) * self::FACTOR_CALLES_LOCAL, 2);
        $regresoMin = max(1, (int) round(($regresoKm / self::VELOCIDAD_URBANA_KMH) * 60));
        $distanciaTotal = round($distanciaTotal + $regresoKm, 2);
        $duracionTotal += $regresoMin;
        $trazado[] = [$origenLat, $origenLng];

        return [
            'paradas' => $paradas,
            'distancia_km' => $distanciaTotal,
            'duracion_min' => max(1, $duracionTotal),
            'encoded_polyline' => self::codificarPolilinea($trazado),
            'fuente' => 'local',
        ];
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $radioTierra = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $radioTierra * asin(sqrt(min(1.0, $a)));
    }

    /**
     * Codifica una lista de pares [lat, lng] en formato polilínea estándar (precisión 1e5).
     *
     * @param  array<int, array{0: float, 1: float}>  $puntos
     */
    public static function codificarPolilinea(array $puntos): string
    {
        $resultado = '';
        $prevLat = 0;
        $prevLng = 0;

        foreach ($puntos as [$lat, $lng]) {
            $latE5 = (int) round($lat * 1e5);
            $lngE5 = (int) round($lng * 1e5);
            $resultado .= self::codificarValor($latE5 - $prevLat);
            $resultado .= self::codificarValor($lngE5 - $prevLng);
            $prevLat = $latE5;
            $prevLng = $lngE5;
        }

        return $resultado;
    }

    private static function codificarValor(int $valor): string
    {
        $v = $valor < 0 ? ~($valor << 1) : ($valor << 1);
        $salida = '';

        while ($v >= 0x20) {
            $salida .= chr((0x20 | ($v & 0x1F)) + 63);
            $v >>= 5;
        }

        return $salida.chr($v + 63);
    }
}
