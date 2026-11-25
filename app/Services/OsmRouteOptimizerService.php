<?php

namespace App\Services;

use App\Models\Punto;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * @phpstan-type ParadaOptimizada array{
 *     punto: Punto,
 *     orden: int,
 *     distancia_tramo_km: float,
 *     duracion_tramo_min: int,
 *     hora_estimada_llegada: CarbonInterface
 * }
 * @phpstan-type Optimizacion array{
 *     paradas: array<int, ParadaOptimizada>,
 *     distancia_km: float,
 *     duracion_min: int,
 *     encoded_polyline: string,
 *     fuente: string
 * }
 */
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
     * @return Optimizacion
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
            return self::sinParadas();
        }

        return $this->intentarOsrm($lista, $origenLat, $origenLng, $salida)
            ?? $this->trazadoLocal(
                $this->vecinoMasCercano($lista, $origenLat, $origenLng),
                $origenLat,
                $origenLng,
                $origenLat,
                $origenLng,
                $salida,
            );
    }

    /**
     * Orden de visita al iniciar la ruta: primero la parada más cercana a la
     * posición del chofer y, desde cada parada, la siguiente más cercana (vecino
     * más cercano), con regreso a la planta al final. La cercanía es la distancia
     * por calles de OSRM (`/table`) y el trazado sale de `/route`; si OSRM no
     * responde, se usa la distancia en línea recta y un trazado aproximado.
     *
     * @param  Collection<int, Punto>  $puntos
     * @return Optimizacion
     */
    public function ordenarPorCercania(
        Collection $puntos,
        float $origenLat,
        float $origenLng,
        ?CarbonInterface $horaSalida = null,
    ): array {
        $salida = ($horaSalida ?? now())->copy();
        $lista = $puntos->values();

        if ($lista->isEmpty()) {
            return self::sinParadas();
        }

        $distancias = $this->distanciasOsrm($lista, $origenLat, $origenLng);
        $secuencia = $this->vecinoMasCercano($lista, $origenLat, $origenLng, $distancias);

        // Si OSRM no entregó la matriz tampoco se le pide el trazado: el chofer no espera un segundo timeout.
        $porCalles = $distancias === null ? null : $this->rutaOsrm($secuencia, $origenLat, $origenLng, $salida);

        return $porCalles ?? $this->trazadoLocal(
            $secuencia,
            $origenLat,
            $origenLng,
            self::PLANTA_LAT,
            self::PLANTA_LNG,
            $salida,
        );
    }

    /**
     * @param  Collection<int, Punto>  $puntos
     * @return Optimizacion|null
     */
    private function intentarOsrm(
        Collection $puntos,
        float $origenLat,
        float $origenLng,
        CarbonInterface $salida,
    ): ?array {
        $json = $this->consultarOsrm('trip', [[$origenLat, $origenLng], ...self::coordenadas($puntos)], [
            'source' => 'first',
            'roundtrip' => 'true',
            'overview' => 'full',
            'geometries' => 'polyline',
            'steps' => 'false',
        ]);

        $trips = $json['trips'] ?? null;
        $waypoints = $json['waypoints'] ?? null;
        if (! is_array($trips) || $trips === [] || ! is_array($waypoints) || count($waypoints) !== $puntos->count() + 1) {
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

        return $this->resultadoOsrm(array_values($ordenados), $trips[0], $salida);
    }

    /**
     * Matriz de distancias por calle (metros) entre el origen (fila y columna 0)
     * y los puntos (1..N). Una celda es null cuando OSRM no encontró camino.
     *
     * @param  Collection<int, Punto>  $puntos
     * @return array<int, array<int, float|null>>|null
     */
    private function distanciasOsrm(Collection $puntos, float $origenLat, float $origenLng): ?array
    {
        $json = $this->consultarOsrm(
            'table',
            [[$origenLat, $origenLng], ...self::coordenadas($puntos)],
            ['annotations' => 'distance'],
        );

        $matriz = $json['distances'] ?? null;
        $tamano = $puntos->count() + 1;
        if (! is_array($matriz) || count($matriz) !== $tamano) {
            return null;
        }

        $distancias = [];
        foreach (array_values($matriz) as $fila) {
            if (! is_array($fila) || count($fila) !== $tamano) {
                return null;
            }
            $distancias[] = array_map(
                static fn (mixed $metros): ?float => is_numeric($metros) ? (float) $metros : null,
                array_values($fila),
            );
        }

        return $distancias;
    }

    /**
     * Trazado por calles de OSRM para un orden ya decidido: origen → paradas → planta.
     *
     * @param  array<int, Punto>  $secuencia
     * @return Optimizacion|null
     */
    private function rutaOsrm(array $secuencia, float $origenLat, float $origenLng, CarbonInterface $salida): ?array
    {
        $json = $this->consultarOsrm('route', [
            [$origenLat, $origenLng],
            ...self::coordenadas($secuencia),
            [self::PLANTA_LAT, self::PLANTA_LNG],
        ], [
            'overview' => 'full',
            'geometries' => 'polyline',
            'steps' => 'false',
        ]);

        $rutas = $json['routes'] ?? null;

        return is_array($rutas) && $rutas !== [] ? $this->resultadoOsrm($secuencia, $rutas[0], $salida) : null;
    }

    /**
     * Arma el resultado con los tramos de un viaje o una ruta de OSRM
     * (`trips[0]` o `routes[0]`); null si la respuesta viene incompleta.
     *
     * @param  array<int, Punto>  $secuencia  paradas en orden de visita
     * @return Optimizacion|null
     */
    private function resultadoOsrm(array $secuencia, mixed $viaje, CarbonInterface $salida): ?array
    {
        if (! is_array($viaje)) {
            return null;
        }

        $geometry = $viaje['geometry'] ?? null;
        $legs = $viaje['legs'] ?? null;
        if (! is_string($geometry) || $geometry === '' || ! is_array($legs) || count($legs) < count($secuencia)) {
            return null;
        }

        $paradas = [];
        $reloj = $salida->copy();

        foreach (array_values($secuencia) as $i => $punto) {
            $leg = $legs[$i] ?? [];
            $distKm = round(((float) ($leg['distance'] ?? 0)) / 1000, 2);
            $durMin = max(1, (int) round(((float) ($leg['duration'] ?? 60)) / 60));

            $reloj = $reloj->copy()->addMinutes($durMin);
            $paradas[] = [
                'punto' => $punto,
                'orden' => $i + 1,
                'distancia_tramo_km' => $distKm,
                'duracion_tramo_min' => $durMin,
                'hora_estimada_llegada' => $reloj->copy(),
            ];
            $reloj = $reloj->copy()->addMinutes(self::MINUTOS_POR_PARADA);
        }

        $distanciaTotalKm = round(((float) ($viaje['distance'] ?? 0)) / 1000, 2);
        $duracionConduccionMin = (int) round(((float) ($viaje['duration'] ?? 0)) / 60);
        $duracionTotalMin = max(1, $duracionConduccionMin + (count($secuencia) * self::MINUTOS_POR_PARADA));

        return [
            'paradas' => $paradas,
            'distancia_km' => $distanciaTotalKm,
            'duracion_min' => $duracionTotalMin,
            'encoded_polyline' => $geometry,
            'fuente' => 'osrm',
        ];
    }

    /**
     * Llama a un servicio de OSRM (`trip`, `table` o `route`) con coordenadas
     * [lat, lng]. Devuelve el JSON solo si OSRM respondió `code: Ok`; null ante
     * error, respuesta inválida o timeout.
     *
     * @param  array<int, array{0: float, 1: float}>  $coordenadas
     * @param  array<string, string>  $parametros
     * @return array<mixed>|null
     */
    private function consultarOsrm(string $servicio, array $coordenadas, array $parametros): ?array
    {
        $baseUrl = rtrim((string) config('services.osrm.base_url', 'https://router.project-osrm.org'), '/');
        $timeout = max(2, (int) config('services.osrm.timeout', 6));

        $lista = implode(';', array_map(
            static fn (array $c): string => sprintf('%.6f,%.6f', $c[1], $c[0]),
            $coordenadas,
        ));

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders(['User-Agent' => 'EcoRutaTemuco/1.0'])
                ->get($baseUrl.'/'.$servicio.'/v1/driving/'.$lista, $parametros);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();

        return is_array($json) && ($json['code'] ?? null) === 'Ok' ? $json : null;
    }

    /**
     * Vecino más cercano: desde el origen va a la parada más cercana y desde
     * cada parada a la siguiente más cercana. Con la matriz de OSRM compara la
     * distancia por calles (un par sin camino queda al final); sin ella, la
     * distancia en línea recta. A igual distancia gana el primero de la lista.
     *
     * @param  Collection<int, Punto>  $puntos
     * @param  array<int, array<int, float|null>>|null  $distancias  origen en 0, puntos en 1..N
     * @return array<int, Punto>
     */
    private function vecinoMasCercano(
        Collection $puntos,
        float $origenLat,
        float $origenLng,
        ?array $distancias = null,
    ): array {
        $lista = $puntos->values()->all();
        $restantes = array_keys($lista);
        $actualLat = $origenLat;
        $actualLng = $origenLng;
        $fila = 0;
        $secuencia = [];

        while ($restantes !== []) {
            $mejor = 0;
            $mejorDistancia = INF;

            foreach ($restantes as $posicion => $i) {
                $d = $distancias === null
                    ? $this->haversineKm($actualLat, $actualLng, (float) $lista[$i]->latitud, (float) $lista[$i]->longitud)
                    : ($distancias[$fila][$i + 1] ?? INF);
                if ($d < $mejorDistancia) {
                    $mejorDistancia = $d;
                    $mejor = $posicion;
                }
            }

            $elegido = $restantes[$mejor];
            array_splice($restantes, $mejor, 1);

            $secuencia[] = $lista[$elegido];
            $actualLat = (float) $lista[$elegido]->latitud;
            $actualLng = (float) $lista[$elegido]->longitud;
            $fila = $elegido + 1;
        }

        return $secuencia;
    }

    /**
     * Respaldo local para un orden ya decidido: tramos en línea recta ajustados
     * por el trazado de calles, velocidad urbana fija y regreso al destino.
     *
     * @param  array<int, Punto>  $secuencia
     * @return Optimizacion
     */
    private function trazadoLocal(
        array $secuencia,
        float $origenLat,
        float $origenLng,
        float $destinoLat,
        float $destinoLng,
        CarbonInterface $salida,
    ): array {
        $actualLat = $origenLat;
        $actualLng = $origenLng;
        $reloj = $salida->copy();
        $paradas = [];
        $trazado = [[$origenLat, $origenLng]];
        $distanciaTotal = 0.0;
        $duracionTotal = 0;

        foreach (array_values($secuencia) as $i => $punto) {
            $lat = (float) $punto->latitud;
            $lng = (float) $punto->longitud;
            $tramoKm = round($this->haversineKm($actualLat, $actualLng, $lat, $lng) * self::FACTOR_CALLES_LOCAL, 2);
            $tramoMin = max(1, (int) round(($tramoKm / self::VELOCIDAD_URBANA_KMH) * 60));

            $reloj = $reloj->copy()->addMinutes($tramoMin);
            $paradas[] = [
                'punto' => $punto,
                'orden' => $i + 1,
                'distancia_tramo_km' => $tramoKm,
                'duracion_tramo_min' => $tramoMin,
                'hora_estimada_llegada' => $reloj->copy(),
            ];

            $reloj = $reloj->copy()->addMinutes(self::MINUTOS_POR_PARADA);
            $distanciaTotal += $tramoKm;
            $duracionTotal += $tramoMin + self::MINUTOS_POR_PARADA;

            $actualLat = $lat;
            $actualLng = $lng;
            $trazado[] = [$lat, $lng];
        }

        // Tramo de regreso (a la planta).
        $regresoKm = round($this->haversineKm($actualLat, $actualLng, $destinoLat, $destinoLng) * self::FACTOR_CALLES_LOCAL, 2);
        $regresoMin = max(1, (int) round(($regresoKm / self::VELOCIDAD_URBANA_KMH) * 60));
        $distanciaTotal = round($distanciaTotal + $regresoKm, 2);
        $duracionTotal += $regresoMin;
        $trazado[] = [$destinoLat, $destinoLng];

        return [
            'paradas' => $paradas,
            'distancia_km' => $distanciaTotal,
            'duracion_min' => max(1, $duracionTotal),
            'encoded_polyline' => self::codificarPolilinea($trazado),
            'fuente' => 'local',
        ];
    }

    /**
     * @return Optimizacion
     */
    private static function sinParadas(): array
    {
        return [
            'paradas' => [],
            'distancia_km' => 0.0,
            'duracion_min' => 0,
            'encoded_polyline' => '',
            'fuente' => 'local',
        ];
    }

    /**
     * @param  iterable<int, Punto>  $puntos
     * @return array<int, array{0: float, 1: float}>
     */
    private static function coordenadas(iterable $puntos): array
    {
        $coordenadas = [];
        foreach ($puntos as $punto) {
            $coordenadas[] = [(float) $punto->latitud, (float) $punto->longitud];
        }

        return $coordenadas;
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

    /**
     * Decodifica una polilínea estándar (precisión 1e5) a pares [lat, lng].
     *
     * @return array<int, array{0: float, 1: float}>
     */
    public static function decodificarPolilinea(string $codificada): array
    {
        $puntos = [];
        $indice = 0;
        $largo = strlen($codificada);
        $lat = 0;
        $lng = 0;

        while ($indice < $largo) {
            foreach ([0, 1] as $eje) {
                $resultado = 0;
                $desplazamiento = 0;
                do {
                    if ($indice >= $largo) {
                        return $puntos;
                    }
                    $b = ord($codificada[$indice++]) - 63;
                    $resultado |= ($b & 0x1F) << $desplazamiento;
                    $desplazamiento += 5;
                } while ($b >= 0x20);
                $delta = ($resultado & 1) ? ~($resultado >> 1) : ($resultado >> 1);
                if ($eje === 0) {
                    $lat += $delta;
                } else {
                    $lng += $delta;
                }
            }
            $puntos[] = [$lat / 1e5, $lng / 1e5];
        }

        return $puntos;
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
