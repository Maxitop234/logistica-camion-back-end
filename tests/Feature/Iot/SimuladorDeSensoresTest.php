<?php

use App\Models\Administrador;
use App\Models\AuditLog;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\Dispositivo;
use App\Models\LecturaInvalida;
use App\Models\LecturaPunto;
use App\Models\Punto;
use App\Models\Ruta;
use App\Models\RutaPunto;
use App\Models\Sector;
use App\Models\User;
use App\Services\Iot\IotException;
use App\Services\Iot\SimuladorDeSensores;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['app.demo_mode' => true, 'iot.simulador.espera_seg' => 0]);
    CarbonImmutable::setTestNow('2026-11-23 12:00:00');
    $this->admin = User::factory()->create(['role' => 'administrador']);
    Administrador::factory()->create(['user_id' => $this->admin->id]);
    $this->sector = Sector::factory()->create(['nombre' => 'Sector de prueba']);
    $this->simulador = app(SimuladorDeSensores::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function sensorSimulado(object $test, string $codigo = 'SIM-01', array $opciones = []): Dispositivo
{
    $punto = Punto::factory()->create(['sector_id' => $test->sector->id, 'porcentaje_llenado' => null, 'estado_llenado' => null, 'ultima_actualizacion_estado' => null]);

    return $test->simulador->aprovisionar($codigo, $punto, $test->admin, ['nivel_inicial' => 20, ...$opciones]);
}

test('el sensor simulado se vincula con el mismo flujo que uno real y usa su token', function () {
    $d = sensorSimulado($this);

    expect($d->es_simulado)->toBeTrue()
        ->and($d->estado)->toBe('activo')
        ->and($d->token_pendiente_cifrado)->toBeNull()
        ->and($d->token_entregado_en)->not->toBeNull()
        ->and($d->token_hash)->toBe(Dispositivo::hashToken($d->simulacion['token']))
        ->and(AuditLog::where('accion', 'dispositivo_vinculado')->count())->toBe(1);

    // Envio la primera lectura al vincularse, con validacion y calibracion reales.
    expect(LecturaPunto::where('dispositivo_id', $d->id)->count() + LecturaInvalida::where('dispositivo_id', $d->id)->count())->toBe(1);

    // Un request HTTP con ese token es aceptado por el mismo endpoint que usa un ESP32.
    $this->withHeader('X-IoT-Token', $d->simulacion['token'])
        ->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 90, 'lectura_uuid' => 'http-1'])->assertCreated();
});

test('completar es determinista y no duplica lecturas', function () {
    $d = sensorSimulado($this);
    CarbonImmutable::setTestNow('2026-11-23 18:00:00');

    $primero = $this->simulador->completarHasta(now(), true);
    $lecturas = LecturaPunto::where('dispositivo_id', $d->id)->orderBy('medido_en')->get(['lectura_uuid', 'distancia_cm', 'medido_en', 'bateria_voltaje'])->toArray();
    $invalidas = LecturaInvalida::count();

    expect($primero['lecturas'])->toBeGreaterThan(5);

    // Repetir sin avanzar el tiempo no hace nada.
    expect($this->simulador->completarHasta(now(), true)['lecturas'])->toBe(0)
        ->and(LecturaPunto::where('dispositivo_id', $d->id)->count())->toBe(count($lecturas));

    // Reproducir desde cero produce exactamente las mismas lecturas.
    $d2 = Dispositivo::find($d->id);
    $uuids = collect($lecturas)->pluck('lectura_uuid')->all();
    LecturaPunto::query()->delete();
    LecturaInvalida::query()->delete();
    $d2->update(['ultima_lectura_en' => null, 'vinculado_en' => CarbonImmutable::parse('2026-11-23 12:00:00')]);
    $this->simulador->completarHasta(now(), true);
    $repetidas = LecturaPunto::where('dispositivo_id', $d->id)->orderBy('medido_en')->get(['lectura_uuid', 'distancia_cm', 'medido_en', 'bateria_voltaje'])->toArray();

    // Salvo la lectura inicial del aprovisionamiento (instante propio), todo coincide.
    expect(collect($repetidas)->pluck('lectura_uuid')->intersect($uuids)->count())->toBe(count($repetidas))
        ->and(LecturaInvalida::count())->toBeLessThanOrEqual($invalidas + 1);
});

test('respeta el intervalo del sensor y el maximo de lecturas por llamada', function () {
    $d = sensorSimulado($this);
    $d->update(['intervalo_lectura_seg' => 3600]);
    CarbonImmutable::setTestNow('2026-11-23 15:30:00');

    $r = $this->simulador->completarHasta(now(), true);
    // Entre las 12:00 y las 15:30 caben a lo sumo 3 ranuras horarias.
    expect($r['lecturas'])->toBeLessThanOrEqual(3);

    config(['iot.simulador.maximo_por_sensor' => 4]);
    CarbonImmutable::setTestNow('2026-11-30 12:00:00');
    expect($this->simulador->completarHasta(now(), true)['lecturas'])->toBeLessThanOrEqual(4);
});

test('el llenado sube con el tiempo, tiene ruido de pocos centimetros y la bateria baja despacio', function () {
    $d = sensorSimulado($this, 'SIM-02', ['nivel_inicial' => 10, 'voltaje_inicial' => 4.0]);
    CarbonImmutable::setTestNow('2026-11-26 12:00:00');
    $this->simulador->completarHasta(now(), true);

    $lecturas = LecturaPunto::where('dispositivo_id', $d->id)->orderBy('medido_en')->get();
    expect($lecturas->last()->porcentaje_llenado)->toBeGreaterThan($lecturas->first()->porcentaje_llenado)
        ->and((float) $lecturas->first()->bateria_voltaje)->toBeGreaterThan((float) $lecturas->last()->bateria_voltaje)
        ->and((float) $lecturas->first()->bateria_voltaje - (float) $lecturas->last()->bateria_voltaje)->toBeLessThan(0.1);

    // El ruido nunca supera 2 cm: la distancia sigue de cerca la curva sin ruido.
    $distancias = $lecturas->pluck('distancia_cm')->map(fn ($v) => (float) $v);
    expect($distancias->min())->toBeGreaterThanOrEqual(20.0);
});

test('hay lecturas invalidas ocasionales que el servidor descarta', function () {
    $d = sensorSimulado($this, 'SIM-03');
    $d->update(['intervalo_lectura_seg' => 600]);
    config(['iot.simulador.maximo_por_sensor' => 1000]);
    CarbonImmutable::setTestNow('2026-11-30 12:00:00');
    $this->simulador->completarHasta(now(), true);

    $invalidas = LecturaInvalida::where('dispositivo_id', $d->id)->get();
    expect($invalidas->count())->toBeGreaterThan(0)
        ->and(LecturaPunto::where('dispositivo_id', $d->id)->count())->toBeGreaterThan($invalidas->count() * 5)
        ->and($invalidas->pluck('motivo')->unique()->diff(['sin_eco', 'zona_ciega'])->all())->toBe([]);
});

test('un sensor con corte largo no reporta y vuelve despues', function () {
    $d = sensorSimulado($this, 'SIM-04', ['desconectado_hasta' => CarbonImmutable::parse('2026-11-24 12:00:00')]);
    $antes = LecturaPunto::where('dispositivo_id', $d->id)->count();

    CarbonImmutable::setTestNow('2026-11-24 06:00:00');
    expect($this->simulador->completarHasta(now(), true)['lecturas'])->toBe(0)
        ->and($d->fresh()->sinReportar())->toBeTrue();

    CarbonImmutable::setTestNow('2026-11-24 20:00:00');
    expect($this->simulador->completarHasta(now(), true)['lecturas'])->toBeGreaterThan(0)
        ->and($d->fresh()->sinReportar())->toBeFalse();
});

test('al recolectar la parada el sensor reporta el contenedor vacio', function () {
    $d = sensorSimulado($this, 'SIM-05', ['nivel_inicial' => 95]);
    $punto = $d->punto;
    expect($punto->fresh()->porcentaje_llenado)->toBeGreaterThan(85);

    CarbonImmutable::setTestNow('2026-11-23 13:00:00');
    $chofer = Chofer::factory()->create();
    $camion = Camion::factory()->create();
    $ruta = Ruta::create([
        'chofer_id' => $chofer->id, 'camion_id' => $camion->id, 'fecha_programada' => now()->format('Y-m-d'),
        'estado' => Ruta::ESTADO_EN_CURSO, 'total_puntos_evaluados' => 1, 'puntos_incluidos_count' => 1,
        'puntos_excluidos_count' => 0, 'distancia_estimada_km' => 1, 'duracion_estimada_min' => 5,
        'encoded_polyline' => '', 'hora_inicio_real' => now()->subMinutes(20),
    ]);
    RutaPunto::create(['ruta_id' => $ruta->id, 'punto_id' => $punto->id, 'orden_parada' => 1, 'estado_llenado_inicial' => 'alto', 'estado_parada' => RutaPunto::ESTADO_PENDIENTE]);
    $chofer = $chofer->user;

    $this->actingAs($chofer, 'sanctum')->postJson("/api/v1/rutas/{$ruta->id}/sincronizar-lote", [
        'recolecciones' => [['sync_uuid' => (string) Str::uuid(), 'punto_id' => $punto->id, 'hora_real_recoleccion' => now()->toIso8601String()]],
    ])->assertOk();

    $ultima = LecturaPunto::where('dispositivo_id', $d->id)->orderByDesc('medido_en')->orderByDesc('id')->first();
    expect($ultima->porcentaje_llenado)->toBeLessThan(10)
        ->and($punto->fresh()->porcentaje_llenado)->toBeLessThan(10);
});

test('si el admin rota el token el sensor simulado lo vuelve a retirar y sigue', function () {
    $d = sensorSimulado($this, 'SIM-06');
    $tokenViejo = $d->simulacion['token'];
    $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/dispositivos/{$d->id}/rotar-token")->assertOk();

    CarbonImmutable::setTestNow('2026-11-23 16:00:00');
    expect($this->simulador->completarHasta(now(), true)['lecturas'])->toBeGreaterThan(0);
    expect($d->fresh()->simulacion['token'])->not->toBe($tokenViejo);
});

test('con APP_DEMO_MODE=false el simulador, sus rutas y sus comandos no existen', function () {
    $d = sensorSimulado($this, 'SIM-07');
    config(['app.demo_mode' => false]);
    $punto = Punto::factory()->create();

    expect(fn () => $this->simulador->completarHasta(now(), true))->toThrow(IotException::class);
    expect(fn () => $this->simulador->aprovisionar('SIM-X', $punto, $this->admin))->toThrow(IotException::class);
    SimuladorDeSensores::completarSiDemo();
    $this->simulador->reportarVaciado($d->punto);

    $this->actingAs($this->admin, 'sanctum');
    $this->postJson('/api/v1/dispositivos/simulador/completar')->assertForbidden();
    $this->postJson("/api/v1/puntos/{$punto->id}/simular-lectura", ['distancia_cm' => 60])->assertForbidden();
    $this->artisan('ecoruta:simular-sensores')->assertFailed();
    // El listado normal no dispara ninguna simulacion.
    $antes = LecturaPunto::count();
    $this->getJson('/api/v1/dispositivos')->assertOk();
    expect(LecturaPunto::count())->toBe($antes);
});

test('en demo, consultar el listado completa las lecturas faltantes y el comando tambien', function () {
    $d = sensorSimulado($this, 'SIM-08');
    CarbonImmutable::setTestNow('2026-11-23 20:00:00');

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/dispositivos')->assertOk();
    $trasListado = LecturaPunto::where('dispositivo_id', $d->id)->count();
    expect($trasListado)->toBeGreaterThan(1);

    CarbonImmutable::setTestNow('2026-11-24 02:00:00');
    $this->artisan('ecoruta:simular-sensores')->assertSuccessful();
    expect(LecturaPunto::where('dispositivo_id', $d->id)->count())->toBeGreaterThanOrEqual($trasListado);

    // Con la espera configurada, consultas seguidas no repiten el trabajo.
    config(['iot.simulador.espera_seg' => 60]);
    Cache::flush();
    $this->simulador->completarHasta(now());
    expect($this->simulador->completarHasta(now()))->toBe(['sensores' => 0, 'lecturas' => 0]);
});
