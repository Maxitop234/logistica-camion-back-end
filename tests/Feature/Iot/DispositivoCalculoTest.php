<?php

use App\Models\Dispositivo;
use App\Models\LecturaInvalida;
use App\Models\LecturaPunto;
use App\Models\Punto;

test('el porcentaje usa la calibracion del dispositivo con la distancia de lleno', function () {
    $d = Dispositivo::factory()->make(['altura_contenedor_cm' => 125, 'distancia_lleno_cm' => 25, 'tipo_sensor' => 'jsn_sr04t']);

    // (125 - 75) / (125 - 25) = 50 %.
    expect($d->evaluarDistancia(75.0))->toBe(['valida' => true, 'porcentaje' => 50, 'motivo' => null])
        ->and($d->evaluarDistancia(125.0)['porcentaje'])->toBe(0)
        ->and($d->evaluarDistancia(130.0)['porcentaje'])->toBe(0)
        // Entre la zona ciega (20) y la distancia de lleno (25): contenedor lleno, 100 %.
        ->and($d->evaluarDistancia(22.0)['porcentaje'])->toBe(100);
});

test('las lecturas imposibles son invalidas y nunca cuentan como 100 por ciento', function () {
    $d = Dispositivo::factory()->make(['altura_contenedor_cm' => 120, 'distancia_lleno_cm' => 25, 'tipo_sensor' => 'jsn_sr04t']);

    expect($d->evaluarDistancia(8.0))->toBe(['valida' => false, 'porcentaje' => null, 'motivo' => 'zona_ciega'])
        ->and($d->evaluarDistancia(null)['motivo'])->toBe('sin_eco')
        ->and($d->evaluarDistancia(0.0)['motivo'])->toBe('sin_eco')
        ->and($d->evaluarDistancia(700.0)['motivo'])->toBe('fuera_de_rango')
        // Más lejos que el fondo declarado más la tolerancia: el sensor apunta a otra cosa.
        ->and($d->evaluarDistancia(150.0)['motivo'])->toBe('fuera_de_rango');

    // El HC-SR04 tiene una zona ciega mucho menor.
    $hc = Dispositivo::factory()->make(['altura_contenedor_cm' => 120, 'distancia_lleno_cm' => 5, 'tipo_sensor' => 'hc_sr04']);
    expect($hc->evaluarDistancia(8.0)['valida'])->toBeTrue();
});

test('por el endpoint una lectura en zona ciega se guarda como invalida y no cambia el punto', function () {
    $punto = Punto::factory()->create(['porcentaje_llenado' => 30, 'estado_llenado' => 'bajo', 'ultima_actualizacion_estado' => now()->subHour()]);
    Dispositivo::factory()->conToken('eco_iot_t1')->create(['punto_id' => $punto->id]);

    for ($i = 1; $i <= 3; $i++) {
        $this->withHeader('X-IoT-Token', 'eco_iot_t1')
            ->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 7, 'lectura_uuid' => "inv-{$i}"])
            ->assertCreated()
            ->assertJsonPath('valida', false)
            ->assertJsonPath('motivo_invalido', 'zona_ciega')
            ->assertJsonPath('lectura', null);
    }

    expect(LecturaInvalida::count())->toBe(3)
        ->and(LecturaPunto::count())->toBe(0)
        ->and($punto->fresh()->porcentaje_llenado)->toBe(30)
        ->and(Dispositivo::first()->lecturas_invalidas_seguidas)->toBe(3);

    // Una lectura valida reinicia el contador.
    $this->withHeader('X-IoT-Token', 'eco_iot_t1')
        ->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 60, 'bateria_voltaje' => 3.9, 'rssi_dbm' => -70, 'lectura_uuid' => 'ok-1'])
        ->assertCreated()
        ->assertJsonPath('valida', true)
        ->assertJsonPath('data.porcentaje_llenado', 63);

    $d = Dispositivo::first();
    expect($d->lecturas_invalidas_seguidas)->toBe(0)
        ->and($d->rssi_dbm)->toBe(-70)
        ->and((float) $d->bateria_voltaje)->toBe(3.9);
});

test('la ingesta acepta X-IoT-Token y Bearer, rechaza dispositivos desactivados y sin punto', function () {
    $punto = Punto::factory()->create();
    $d = Dispositivo::factory()->conToken('eco_iot_t2')->create(['punto_id' => $punto->id]);

    $this->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 60])->assertUnauthorized();
    $this->withToken('eco_iot_t2')->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 60, 'lectura_uuid' => 'a'])
        ->assertCreated()
        ->assertJsonPath('configuracion.intervalo_lectura_seg', 3600);

    $this->withHeader('X-IoT-Token', 'eco_iot_t2')->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 60, 'lectura_uuid' => 'a'])
        ->assertOk()
        ->assertJsonPath('duplicada', true);

    $d->update(['punto_id' => null]);
    $this->withHeader('X-IoT-Token', 'eco_iot_t2')->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 60])->assertStatus(409);

    $d->update(['estado' => Dispositivo::ESTADO_DESACTIVADO]);
    $this->withHeader('X-IoT-Token', 'eco_iot_t2')->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 60])->assertForbidden();
});

test('la migracion de datos convierte el token por punto en un dispositivo con el mismo hash', function () {
    $migracion = require database_path('migrations/2026_11_23_100000_create_dispositivos_table.php');
    $punto = Punto::factory()->create(['sensor_codigo' => 'ESP32-VIEJO', 'sensor_token_hash' => Punto::hashSensorToken('token_viejo')]);

    $metodo = new ReflectionMethod($migracion, 'migrarTokensPorPunto');
    $metodo->invoke($migracion);

    $d = Dispositivo::where('punto_id', $punto->id)->firstOrFail();
    expect($d->codigo_hardware)->toBe('ESP32-VIEJO')
        ->and($d->token_hash)->toBe(Dispositivo::hashToken('token_viejo'))
        ->and($d->estado)->toBe('activo');

    // El token ya instalado en el sensor sigue valiendo, ahora como token de dispositivo.
    $this->withToken('token_viejo')->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 60])->assertCreated();
});
