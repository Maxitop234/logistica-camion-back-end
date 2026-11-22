<?php

namespace App\Services\Iot;

use App\Models\Dispositivo;
use App\Models\Punto;
use App\Models\RutaPunto;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Sensores simulados de la demo (solo con `APP_DEMO_MODE=true`).
 *
 * Cada sensor simulado es un dispositivo real: se vincula con el mismo flujo que
 * uno físico (anuncio, aprobación, retiro único del token) y envía sus lecturas
 * por `RecepcionDeLecturas`, igual que el endpoint HTTP. No hay scheduler: las
 * lecturas faltantes se completan de forma determinista al despertar o consultar.
 * Todo valor es función pura del sensor y del instante, así que repetir el
 * completado nunca duplica ni cambia lo ya enviado.
 */
class SimuladorDeSensores
{
    private const PASOS_MAXIMOS = 24 * 14;

    /** @var array<int, float> Factor de llenado por día ISO (1 = lunes). */
    private const FACTOR_DIA = [1 => 0.9, 2 => 1.0, 3 => 1.0, 4 => 1.05, 5 => 1.2, 6 => 1.4, 7 => 1.3];

    public function __construct(
        private readonly RecepcionDeLecturas $recepcion,
        private readonly VinculacionDeDispositivos $vinculacion,
    ) {}

    /**
     * Vincula un sensor simulado con el mismo flujo de uno real.
     *
     * @param  array{perfil?: string, nivel_inicial?: int, desconectado_hasta?: CarbonInterface|null, voltaje_inicial?: float, intervalo_lectura_seg?: int}  $opciones
     */
    public function aprovisionar(string $codigoHardware, Punto $punto, User $admin, array $opciones = []): Dispositivo
    {
        $this->exigirDemo();
        $codigo = $this->vinculacion->registrarCodigo($codigoHardware, 'demo');

        // 1. El "firmware" se anuncia con el código de su etiqueta.
        $this->vinculacion->anunciar([
            'codigo_hardware' => $codigoHardware,
            'codigo_vinculacion' => $codigo,
            'firmware_version' => 'sim-1.0.0',
            'conectividad' => 'wifi',
            'tipo_sensor' => Dispositivo::TIPO_JSN_SR04T,
        ]);
        // 2. El administrador lo aprueba y elige el punto.
        $this->vinculacion->aprobar($codigoHardware, $codigo, $punto, $admin);
        // 3. El "firmware" consulta su estado y retira el token una sola vez.
        $estado = $this->vinculacion->consultarEstado($codigoHardware, $codigo);

        $ahora = now();
        $dispositivo = Dispositivo::where('codigo_hardware', $codigoHardware)->firstOrFail();
        $dispositivo->update([
            'es_simulado' => true,
            'intervalo_lectura_seg' => $opciones['intervalo_lectura_seg'] ?? (int) config('iot.simulador.intervalo_seg', 1800),
            // Es la memoria no volátil (NVS) del ESP32 simulado.
            'simulacion' => [
                'token' => $estado['token'],
                'codigo' => $codigo,
                'perfil' => $opciones['perfil'] ?? 'normal',
                'nivel_inicial' => $opciones['nivel_inicial'] ?? $this->semilla($codigoHardware, 'nivel') % 50 + 10,
                'ancla_en' => $ahora->toIso8601String(),
                'voltaje_inicial' => $opciones['voltaje_inicial'] ?? round(3.85 + ($this->semilla($codigoHardware, 'volt') % 25) / 100, 2),
                'desconectado_hasta' => ($opciones['desconectado_hasta'] ?? null)?->toIso8601String(),
            ],
        ]);

        // Igual que el firmware real, envía su primera lectura apenas tiene token.
        $this->enviarLectura($dispositivo->refresh(), CarbonImmutable::instance($ahora), false);

        return $dispositivo->refresh();
    }

    /**
     * Completa las lecturas pendientes si estamos en demo; no hace nada fuera de ella.
     */
    public static function completarSiDemo(): void
    {
        if (config('app.demo_mode')) {
            app(self::class)->completarHasta(now());
        }
    }

    /**
     * Envía las lecturas que cada sensor simulado debió mandar hasta `$ahora`.
     *
     * @return array{sensores: int, lecturas: int}
     */
    public function completarHasta(CarbonInterface $ahora, bool $forzar = false): array
    {
        $this->exigirDemo();
        $espera = (int) config('iot.simulador.espera_seg', 60);
        if (! $forzar && $espera > 0 && ! Cache::add('iot-simulador-completado', 1, $espera)) {
            return ['sensores' => 0, 'lecturas' => 0];
        }

        $ahora = CarbonImmutable::instance($ahora);
        $sensores = 0;
        $lecturas = 0;

        Dispositivo::query()
            ->where('es_simulado', true)
            ->where('estado', Dispositivo::ESTADO_ACTIVO)
            ->whereNotNull('punto_id')
            ->with('punto.sector')
            ->orderBy('id')
            ->each(function (Dispositivo $dispositivo) use ($ahora, &$sensores, &$lecturas): void {
                $enviadas = $this->completarSensor($dispositivo, $ahora);
                $lecturas += $enviadas;
                $sensores += $enviadas > 0 ? 1 : 0;
            });

        return ['sensores' => $sensores, 'lecturas' => $lecturas];
    }

    /**
     * Tras una recolección el sensor del punto reporta de inmediato el contenedor vacío.
     */
    public function reportarVaciado(Punto $punto): void
    {
        if (! config('app.demo_mode')) {
            return;
        }

        $dispositivo = Dispositivo::query()
            ->where('es_simulado', true)
            ->where('estado', Dispositivo::ESTADO_ACTIVO)
            ->where('punto_id', $punto->id)
            ->first();
        if ($dispositivo instanceof Dispositivo) {
            $this->enviarLectura($dispositivo, CarbonImmutable::instance(now()), true);
        }
    }

    private function completarSensor(Dispositivo $dispositivo, CarbonImmutable $ahora): int
    {
        $paso = max(60, $dispositivo->intervalo_lectura_seg);
        $fase = $this->semilla($dispositivo->codigo_hardware, 'fase') % $paso;
        $desde = $dispositivo->ultima_lectura_en ?? $dispositivo->vinculado_en ?? $ahora;

        // Ranuras alineadas al intervalo del sensor, con un desfase propio de cada uno.
        $primera = (intdiv($desde->getTimestamp() - $fase, $paso) + 1) * $paso + $fase;
        $ultima = (intdiv($ahora->getTimestamp() - $fase, $paso)) * $paso + $fase;
        $maximo = max(1, (int) config('iot.simulador.maximo_por_sensor', 24));
        $primera = max($primera, $ultima - ($maximo - 1) * $paso);

        $enviadas = 0;
        for ($t = $primera; $t <= $ultima; $t += $paso) {
            if ($this->enviarLectura($dispositivo, CarbonImmutable::createFromTimestampUTC($t), false)) {
                $enviadas++;
            }
        }

        return $enviadas;
    }

    private function enviarLectura(Dispositivo $dispositivo, CarbonImmutable $t, bool $ignorarCorte): bool
    {
        $sim = $dispositivo->simulacion ?? [];
        if (! $ignorarCorte && $this->desconectado($dispositivo, $sim, $t)) {
            return false;
        }

        $dispositivo->loadMissing('punto.sector');
        $payload = $this->payload($dispositivo, $sim, $t);

        try {
            $this->recepcion->recibir((string) ($sim['token'] ?? ''), $payload, $t);
        } catch (IotException $e) {
            if ($e->estado !== 401) {
                return false;
            }
            // El administrador rotó el token: el "firmware" lo vuelve a retirar y reintenta.
            $estado = $this->vinculacion->consultarEstado($dispositivo->codigo_hardware, (string) ($sim['codigo'] ?? ''));
            if ($estado['token'] === null) {
                return false;
            }
            $sim['token'] = $estado['token'];
            $dispositivo->update(['simulacion' => $sim]);
            try {
                $this->recepcion->recibir($estado['token'], $payload, $t);
            } catch (IotException) {
                return false;
            }
        }

        $dispositivo->refresh();

        return true;
    }

    /**
     * @param  array<string, mixed>  $sim
     * @return array<string, mixed>
     */
    private function payload(Dispositivo $dispositivo, array $sim, CarbonImmutable $t): array
    {
        $uuid = $this->uuid($dispositivo->codigo_hardware, $t);
        $h = $this->semilla($uuid, 'lectura');
        $base = [
            'lectura_uuid' => $uuid,
            'medido_en' => $t->toIso8601String(),
            'bateria_voltaje' => $this->voltaje($dispositivo, $sim, $t),
            'rssi_dbm' => -58 - ($h % 27),
            'firmware_version' => 'sim-1.0.0',
        ];

        // Falla ocasional del sensor: sin eco, o un valor dentro de la zona ciega.
        if ($h % 53 === 0) {
            return $h % 2 === 0
                ? $base + ['distancia_cm' => null, 'error' => 'sin_eco']
                : $base + ['distancia_cm' => round($dispositivo->zonaCiegaCm() * 0.6, 1)];
        }

        $nivel = $this->nivel($dispositivo, $sim, $t);
        $altura = (float) $dispositivo->altura_contenedor_cm;
        $lleno = (float) $dispositivo->distancia_lleno_cm;
        $ruido = (($this->semilla($uuid, 'ruido') % 401) / 100) - 2.0;
        $distancia = $altura - ($nivel / 100) * ($altura - $lleno) + $ruido;

        return $base + ['distancia_cm' => round(max($dispositivo->zonaCiegaCm() + 0.5, min($altura + 5, $distancia)), 1)];
    }

    /**
     * Llenado (0-100) en `$t`: sube según sector, día y hora, y vuelve a ~2 % con cada recolección.
     *
     * @param  array<string, mixed>  $sim
     */
    private function nivel(Dispositivo $dispositivo, array $sim, CarbonImmutable $t): float
    {
        $inicio = CarbonImmutable::parse((string) ($sim['ancla_en'] ?? $dispositivo->vinculado_en));
        $nivel = (float) ($sim['nivel_inicial'] ?? 20);

        $recoleccion = RutaPunto::query()
            ->where('punto_id', $dispositivo->punto_id)
            ->where('estado_parada', RutaPunto::ESTADO_RECOLECTADO)
            ->whereNotNull('hora_real_recoleccion')
            ->where('hora_real_recoleccion', '>', $inicio)
            ->where('hora_real_recoleccion', '<=', $t)
            ->max('hora_real_recoleccion');
        if ($recoleccion !== null) {
            $inicio = CarbonImmutable::parse((string) $recoleccion);
            $nivel = 2.0;
        }

        $inicio = $inicio->max($t->subHours(self::PASOS_MAXIMOS));
        $factorSector = 0.6 + ($this->semilla($dispositivo->punto->sector->nombre ?? 'sin-sector', 'sector') % 100) / 100;
        $zona = (string) config('ecoruta.zona_horaria', 'America/Santiago');

        for ($cursor = $inicio; $cursor < $t && $nivel < 100; $cursor = $cursor->addHour()) {
            $fin = $cursor->addHour()->min($t);
            $local = $cursor->setTimezone($zona);
            $factorHora = match (true) {
                $local->hour < 7 => 0.3,
                $local->hour < 22 => 1.3,
                default => 0.6,
            };
            $nivel += $factorSector * $factorHora * self::FACTOR_DIA[$local->dayOfWeekIso] * ($cursor->diffInSeconds($fin, true) / 3600);
        }

        return min(100.0, $nivel);
    }

    /**
     * @param  array<string, mixed>  $sim
     */
    private function voltaje(Dispositivo $dispositivo, array $sim, CarbonImmutable $t): float
    {
        $inicial = (float) ($sim['voltaje_inicial'] ?? 3.95);
        if (($sim['perfil'] ?? 'normal') === 'bateria_baja') {
            $inicial = min($inicial, 3.34);
        }
        $ancla = CarbonImmutable::parse((string) ($sim['ancla_en'] ?? $dispositivo->vinculado_en));
        $dias = max(0, $ancla->diffInSeconds($t, false) / 86400);

        return round(max(3.0, $inicial - 0.004 * $dias), 2);
    }

    /**
     * Cortes del sensor: uno largo programado (`desconectado_hasta`) y, al azar pero de forma
     * determinista, algún corte de unas horas en ciertos días ("se desconecta y vuelve").
     *
     * @param  array<string, mixed>  $sim
     */
    private function desconectado(Dispositivo $dispositivo, array $sim, CarbonImmutable $t): bool
    {
        if (isset($sim['desconectado_hasta']) && $t < CarbonImmutable::parse((string) $sim['desconectado_hasta'])) {
            return true;
        }

        $dia = $t->setTimezone((string) config('ecoruta.zona_horaria', 'America/Santiago'))->startOfDay();
        $h = $this->semilla($dispositivo->codigo_hardware.'|'.$dia->toDateString(), 'corte');
        if ($h % 30 !== 0) {
            return false;
        }

        $inicio = $dia->addHours(3 + ($h >> 8) % 14);

        return $t >= $inicio && $t < $inicio->addHours(3 + ($h >> 16) % 6);
    }

    private function uuid(string $hardware, CarbonImmutable $t): string
    {
        $hex = substr(hash('sha1', $hardware.'|'.$t->getTimestamp()), 0, 32);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    private function semilla(string $texto, string $sal): int
    {
        return crc32($sal.'|'.$texto);
    }

    private function exigirDemo(): void
    {
        if (! config('app.demo_mode')) {
            throw new IotException('El simulador de sensores solo existe con APP_DEMO_MODE=true.', 403);
        }
    }
}
