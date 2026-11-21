<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class NominatimGeocoderService
{
    public const CACHE_TTL_SECONDS = 86400;

    public const CACHE_KEY_PREFIX = 'nominatim:reverse:';

    public const RATE_LIMIT_KEY = 'nominatim:global:next_slot_ms';

    public const RATE_LIMIT_LOCK_KEY = 'nominatim:global:slot_lock';

    public const MIN_INTERVAL_MS = 1000;

    public const MAX_WAIT_MS = 2000;

    /**
     * Realiza geocodificación inversa en OpenStreetMap Nominatim cumpliendo
     * estrictamente sus políticas de uso:
     * - caché de 24 h por coordenada redondeada a 5 decimales (solo resultados útiles),
     * - límite global de 1 req/s con espera breve (hasta 2 s) ante concurrencia,
     * - User-Agent propio configurado en `config/services.php`.
     *
     * @return array{
     *   encontrado: bool,
     *   nombre: string|null,
     *   direccion: string|null,
     *   desde_cache: bool,
     *   espero_turno_ms: int,
     *   latitud: float,
     *   longitud: float
     * }
     */
    public function reverse(float $lat, float $lon): array
    {
        $latRedondeada = round($lat, 5);
        $lonRedondeada = round($lon, 5);
        $latKey = number_format($latRedondeada, 5, '.', '');
        $lonKey = number_format($lonRedondeada, 5, '.', '');
        $cacheKey = self::CACHE_KEY_PREFIX."{$latKey},{$lonKey}";

        /** @var array{nombre: string|null, direccion: string|null}|null $enCache */
        $enCache = Cache::get($cacheKey);
        if (is_array($enCache) && ($enCache['nombre'] !== null || $enCache['direccion'] !== null)) {
            return [
                'encontrado' => true,
                'nombre' => $enCache['nombre'],
                'direccion' => $enCache['direccion'],
                'desde_cache' => true,
                'espero_turno_ms' => 0,
                'latitud' => $latRedondeada,
                'longitud' => $lonRedondeada,
            ];
        }

        $turno = $this->adquirirTurnoGlobal();
        if (! $turno['adquirido']) {
            return $this->vacio($latRedondeada, $lonRedondeada, $turno['espero_ms']);
        }

        // Si otra petición concurrente ya pobló la caché mientras esperábamos turno, la reutilizamos.
        /** @var array{nombre: string|null, direccion: string|null}|null $recheck */
        $recheck = Cache::get($cacheKey);
        if (is_array($recheck) && ($recheck['nombre'] !== null || $recheck['direccion'] !== null)) {
            return [
                'encontrado' => true,
                'nombre' => $recheck['nombre'],
                'direccion' => $recheck['direccion'],
                'desde_cache' => true,
                'espero_turno_ms' => $turno['espero_ms'],
                'latitud' => $latRedondeada,
                'longitud' => $lonRedondeada,
            ];
        }

        $baseUrl = rtrim((string) config('services.nominatim.base_url', 'https://nominatim.openstreetmap.org'), '/');
        $userAgent = trim((string) config('services.nominatim.user_agent', 'EcoRutaTemuco/1.0 (contacto@ecoruta.cl)'));
        $timeout = max(1, (int) config('services.nominatim.timeout', 5));

        try {
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'User-Agent' => $userAgent !== '' ? $userAgent : 'EcoRutaTemuco/1.0 (contacto@ecoruta.cl)',
                    'Accept' => 'application/json',
                ])
                ->get("{$baseUrl}/reverse", [
                    'lat' => $latKey,
                    'lon' => $lonKey,
                    'format' => 'jsonv2',
                    'accept-language' => 'es',
                    'addressdetails' => 1,
                ]);
        } catch (Throwable) {
            return $this->vacio($latRedondeada, $lonRedondeada, $turno['espero_ms']);
        }

        if (! $response->successful()) {
            return $this->vacio($latRedondeada, $lonRedondeada, $turno['espero_ms']);
        }

        $payload = $response->json();
        if (! is_array($payload) || isset($payload['error'])) {
            return $this->vacio($latRedondeada, $lonRedondeada, $turno['espero_ms']);
        }

        $extraido = $this->extraerNombreYDireccion($payload);
        if ($extraido['nombre'] === null && $extraido['direccion'] === null) {
            // Nunca cachear resultados vacíos.
            return $this->vacio($latRedondeada, $lonRedondeada, $turno['espero_ms']);
        }

        Cache::put($cacheKey, $extraido, self::CACHE_TTL_SECONDS);

        return [
            'encontrado' => true,
            'nombre' => $extraido['nombre'],
            'direccion' => $extraido['direccion'],
            'desde_cache' => false,
            'espero_turno_ms' => $turno['espero_ms'],
            'latitud' => $latRedondeada,
            'longitud' => $lonRedondeada,
        ];
    }

    /**
     * Extrae nombre (POI > calle + número > calle) y dirección (`calle [número], comuna`)
     * a partir de una respuesta `jsonv2` de Nominatim.
     *
     * @param  array<string, mixed>  $payload
     * @return array{nombre: string|null, direccion: string|null}
     */
    public function extraerNombreYDireccion(array $payload): array
    {
        /** @var array<string, mixed> $address */
        $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];

        $calle = $this->primerTexto($address, [
            'road',
            'pedestrian',
            'footway',
            'residential',
            'path',
            'cycleway',
        ]);
        $numero = $this->primerTexto($address, ['house_number']);
        $comuna = $this->primerTexto($address, [
            'city',
            'town',
            'village',
            'municipality',
            'suburb',
            'county',
        ]);

        $calleConNumero = $calle !== ''
            ? ($numero !== '' ? "{$calle} {$numero}" : $calle)
            : '';

        $nombreLugar = trim((string) ($payload['name'] ?? ''));
        if ($nombreLugar === '') {
            $nombreLugar = $this->primerTexto($address, [
                'amenity',
                'shop',
                'leisure',
                'building',
                'tourism',
                'office',
                'historic',
                'healthcare',
                'public_transport',
            ]);
        }

        $nombre = null;
        if (
            $nombreLugar !== '' &&
            mb_strtolower($nombreLugar) !== mb_strtolower($calle) &&
            mb_strtolower($nombreLugar) !== mb_strtolower($comuna)
        ) {
            $nombre = $nombreLugar;
        } elseif ($calleConNumero !== '') {
            $nombre = $calleConNumero;
        } elseif ($nombreLugar !== '') {
            $nombre = $nombreLugar;
        }

        $partesDireccion = array_values(array_filter(
            [$calleConNumero, $comuna],
            static fn (string $v): bool => $v !== '',
        ));
        $direccion = $partesDireccion !== [] ? implode(', ', $partesDireccion) : null;

        if ($direccion === null && $nombre !== null && $comuna !== '') {
            $direccion = "{$nombre}, {$comuna}";
        }

        return [
            'nombre' => $nombre,
            'direccion' => $direccion,
        ];
    }

    /**
     * Reserva de manera atómica un turno en la ventana global de 1 req/s hacia
     * Nominatim. Si la ventana está ocupada, espera brevemente (hasta 2 s).
     *
     * @return array{adquirido: bool, espero_ms: int}
     */
    private function adquirirTurnoGlobal(): array
    {
        try {
            /** @var array{adquirido: bool, esperar_ms: int} $reserva */
            $reserva = Cache::lock(self::RATE_LIMIT_LOCK_KEY, 5)->block(2, function (): array {
                $ahoraMs = (int) floor(microtime(true) * 1000);
                $siguienteDisponibleMs = (int) Cache::get(self::RATE_LIMIT_KEY, 0);
                $esperarMs = max(0, $siguienteDisponibleMs - $ahoraMs);

                if ($esperarMs > self::MAX_WAIT_MS) {
                    return ['adquirido' => false, 'esperar_ms' => 0];
                }

                $inicioTurnoMs = $ahoraMs + $esperarMs;
                Cache::put(self::RATE_LIMIT_KEY, $inicioTurnoMs + self::MIN_INTERVAL_MS, 15);

                return ['adquirido' => true, 'esperar_ms' => $esperarMs];
            });
        } catch (LockTimeoutException) {
            return ['adquirido' => false, 'espero_ms' => 0];
        }

        if (! $reserva['adquirido']) {
            return ['adquirido' => false, 'espero_ms' => 0];
        }

        if ($reserva['esperar_ms'] > 0) {
            usleep($reserva['esperar_ms'] * 1000);
        }

        return ['adquirido' => true, 'espero_ms' => $reserva['esperar_ms']];
    }

    /**
     * @param  array<string, mixed>  $fuente
     * @param  list<string>  $claves
     */
    private function primerTexto(array $fuente, array $claves): string
    {
        foreach ($claves as $clave) {
            $valor = $fuente[$clave] ?? null;
            if (is_string($valor) && trim($valor) !== '') {
                return trim($valor);
            }
        }

        return '';
    }

    /**
     * @return array{
     *   encontrado: bool,
     *   nombre: string|null,
     *   direccion: string|null,
     *   desde_cache: bool,
     *   espero_turno_ms: int,
     *   latitud: float,
     *   longitud: float
     * }
     */
    private function vacio(float $lat, float $lon, int $esperoMs): array
    {
        return [
            'encontrado' => false,
            'nombre' => null,
            'direccion' => null,
            'desde_cache' => false,
            'espero_turno_ms' => $esperoMs,
            'latitud' => $lat,
            'longitud' => $lon,
        ];
    }
}
