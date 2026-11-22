<?php

use App\Http\Resources\PuntoResource;
use App\Models\Punto;
use App\Models\Sector;
use Illuminate\Support\Carbon;

/*
 * Semana de referencia (hora de Chile, UTC-3 en noviembre):
 * lunes 23-11-2026, martes 24, miércoles 25, jueves 26 y viernes 27.
 */

beforeEach(function () {
    $this->sector = Sector::factory()->dias([2, 4])->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('un punto recolectado el martes en un sector de martes y jueves es vigente hasta el miercoles; el jueves es desconocido', function () {
    // Martes 24-11 a las 11:00 de Chile.
    $martes = Carbon::parse('2026-11-24 14:00:00', 'UTC');
    $punto = Punto::factory()->lleno()->create(['sector_id' => $this->sector->id]);

    $punto->update([
        'estado_llenado' => Punto::ESTADO_VACIO,
        'porcentaje_llenado' => 0,
        'origen_actualizacion' => Punto::ORIGEN_RECOLECCION,
        'ultima_actualizacion_estado' => $martes,
    ]);

    // Miércoles 25-11 a las 23:59:59 de Chile.
    expect($punto->estado_vigente_hasta?->toDateTimeString())->toBe('2026-11-26 02:59:59');

    Carbon::setTestNow(Carbon::parse('2026-11-25 20:00:00', 'UTC')); // miércoles 17:00
    expect($punto->fresh()->estadoVigente())->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-11-26 11:00:00', 'UTC')); // jueves 08:00
    expect($punto->fresh()->estadoVigente())->toBeFalse();
});

test('un ajuste manual del lunes vale hasta el fin del martes (siguiente dia de recoleccion, inclusive)', function () {
    $punto = Punto::factory()->create([
        'sector_id' => $this->sector->id,
        'origen_actualizacion' => Punto::ORIGEN_MANUAL,
        'ultima_actualizacion_estado' => Carbon::parse('2026-11-23 12:00:00', 'UTC'),
    ]);

    expect($punto->estado_vigente_hasta?->toDateTimeString())->toBe('2026-11-25 02:59:59');

    Carbon::setTestNow(Carbon::parse('2026-11-25 12:00:00', 'UTC')); // miércoles
    expect($punto->fresh()->estadoVigente())->toBeFalse();
});

test('el nivel observado por el chofer se comporta como el ajuste manual', function () {
    $punto = Punto::factory()->medio()->create([
        'sector_id' => $this->sector->id,
        'origen_actualizacion' => Punto::ORIGEN_CHOFER,
        'ultima_actualizacion_estado' => Carbon::parse('2026-11-24 14:00:00', 'UTC'), // martes
    ]);

    // Siguiente día de recolección: jueves 26-11.
    expect($punto->estado_vigente_hasta?->toDateTimeString())->toBe('2026-11-27 02:59:59');
});

test('una lectura de sensor es vigente por las horas configuradas', function () {
    config(['ecoruta.estado.vigencia_sensor_horas' => 24]);

    $punto = Punto::factory()->create([
        'sector_id' => $this->sector->id,
        'origen_actualizacion' => Punto::ORIGEN_SENSOR_IOT,
        'ultima_actualizacion_estado' => Carbon::parse('2026-11-24 14:00:00', 'UTC'),
    ]);

    expect($punto->estado_vigente_hasta?->toDateTimeString())->toBe('2026-11-25 14:00:00');
});

test('sin sector el estado manual vale solo hasta el fin del dia local', function () {
    $punto = Punto::factory()->sinSector()->create([
        'origen_actualizacion' => Punto::ORIGEN_MANUAL,
        'ultima_actualizacion_estado' => Carbon::parse('2026-11-24 14:00:00', 'UTC'),
    ]);

    expect($punto->estado_vigente_hasta?->toDateTimeString())->toBe('2026-11-25 02:59:59');
});

test('un punto nunca medido tiene estado null y estado_vigente false (no vacio)', function () {
    $punto = Punto::factory()->sinMedicion()->create(['sector_id' => $this->sector->id]);

    $json = (new PuntoResource($punto->fresh()))->resolve();

    expect($punto->fresh()->estado_vigente_hasta)->toBeNull()
        ->and($json['estado_llenado'])->toBeNull()
        ->and($json['porcentaje_llenado'])->toBeNull()
        ->and($json['estado_vigente'])->toBeFalse();
});
