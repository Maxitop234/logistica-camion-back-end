<?php

use App\Models\Administrador;
use App\Models\Dispositivo;
use App\Models\Punto;
use App\Models\User;

beforeEach(function () {
    config(['app.demo_mode' => false]);
    $this->admin = User::factory()->create(['role' => 'administrador']);
    Administrador::factory()->create(['user_id' => $this->admin->id]);
    $this->actingAs($this->admin, 'sanctum');
});

test('calibrar vacio usa la ultima distancia valida como 0 por ciento y recalcula el punto', function () {
    $punto = Punto::factory()->create();
    $d = Dispositivo::factory()->conToken('eco_iot_c1')->create(['punto_id' => $punto->id, 'altura_contenedor_cm' => 120]);

    // Sin lectura aun no se puede calibrar.
    $this->postJson("/api/v1/dispositivos/{$d->id}/calibrar-vacio")->assertUnprocessable();

    $this->withHeader('X-IoT-Token', 'eco_iot_c1')->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 100, 'lectura_uuid' => 'c-1'])->assertCreated();
    // (120 - 100) / (120 - 25) = 21 %.
    expect($punto->fresh()->porcentaje_llenado)->toBe(21);

    $this->postJson("/api/v1/dispositivos/{$d->id}/calibrar-vacio")
        ->assertOk()
        ->assertJsonPath('data.altura_contenedor_cm', 100)
        ->assertJsonPath('data.punto.porcentaje_llenado', 0);
    expect((float) $punto->fresh()->altura_contenedor_cm)->toBe(100.0);

    // Con la nueva altura, 50 cm da (100 - 50) / (100 - 25) = 67 %.
    $this->withHeader('X-IoT-Token', 'eco_iot_c1')->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 50, 'lectura_uuid' => 'c-2'])
        ->assertJsonPath('data.porcentaje_llenado', 67);
});

test('el detalle muestra lectura en vivo, bateria, senal e historial con validas e invalidas', function () {
    $punto = Punto::factory()->create();
    $d = Dispositivo::factory()->conToken('eco_iot_d1')->create(['punto_id' => $punto->id]);
    $enviar = fn (array $c) => $this->withHeader('X-IoT-Token', 'eco_iot_d1')->postJson('/api/v1/iot/lecturas', $c);

    $enviar(['distancia_cm' => 80, 'bateria_voltaje' => 3.7, 'rssi_dbm' => -66, 'lectura_uuid' => 'h-1', 'medido_en' => now()->subMinutes(10)->toIso8601String()]);
    $enviar(['distancia_cm' => 5, 'lectura_uuid' => 'h-2', 'medido_en' => now()->subMinutes(5)->toIso8601String()]);

    $r = $this->getJson("/api/v1/dispositivos/{$d->id}")->assertOk();
    $r->assertJsonPath('data.estado_conexion', 'en_linea')
        ->assertJsonPath('data.bateria_voltaje', 3.7)
        ->assertJsonPath('data.bateria_porcentaje', 50)
        ->assertJsonPath('data.rssi_dbm', -66)
        ->assertJsonPath('data.ultima_distancia_cm', 80)
        ->assertJsonCount(2, 'data.lecturas')
        ->assertJsonPath('data.lecturas.0.valida', false)
        ->assertJsonPath('data.lecturas.0.motivo', 'zona_ciega')
        ->assertJsonPath('data.lecturas.1.porcentaje_llenado', 42);
});

test('el estado de conexion distingue en linea, sin reportar, bateria baja, pendiente y desactivado', function () {
    $mk = fn (array $a) => Dispositivo::factory()->create(['intervalo_lectura_seg' => 1800, 'ultima_lectura_en' => now()->subMinutes(1), ...$a]);
    $ok = $mk(['ultima_conexion_en' => now()->subMinutes(20), 'bateria_voltaje' => 3.9]);
    $limite = $mk(['ultima_conexion_en' => now()->subMinutes(59)]);
    $sin = $mk(['ultima_conexion_en' => now()->subMinutes(61)]);
    $baja = $mk(['ultima_conexion_en' => now()->subMinutes(5), 'bateria_voltaje' => 3.3]);
    $espera = $mk(['ultima_conexion_en' => now()->subMinutes(3), 'ultima_lectura_en' => null]);
    $nuevo = $mk(['estado' => 'pendiente', 'punto_id' => null]);
    $off = $mk(['estado' => 'desactivado', 'ultima_conexion_en' => now()->subDays(3)]);

    expect($ok->estadoConexion())->toBe('en_linea')
        ->and($limite->estadoConexion())->toBe('en_linea')
        ->and($sin->estadoConexion())->toBe('sin_reportar')
        ->and($espera->estadoConexion())->toBe('esperando_lectura')
        ->and($baja->estadoConexion())->toBe('bateria_baja')
        ->and($nuevo->estadoConexion())->toBe('pendiente')
        ->and($off->estadoConexion())->toBe('desactivado');

    $this->getJson('/api/v1/dispositivos?estado=pendiente')->assertOk()->assertJsonCount(1, 'data');
});

test('un sensor sin lecturas propias no hereda el llenado del punto y se muestra esperando lectura', function () {
    $punto = Punto::factory()->create(['porcentaje_llenado' => 22, 'estado_llenado' => 'bajo']);
    $d = Dispositivo::factory()->create(['punto_id' => $punto->id, 'ultima_conexion_en' => now()->subMinutes(3), 'ultima_lectura_en' => null]);

    $this->getJson("/api/v1/dispositivos/{$d->id}")->assertOk()
        ->assertJsonPath('data.estado_conexion', 'esperando_lectura')
        ->assertJsonPath('data.sin_lecturas', true)
        ->assertJsonPath('data.punto.porcentaje_llenado', null)
        ->assertJsonPath('data.punto.estado_llenado', null);
    expect($punto->fresh()->porcentaje_llenado)->toBe(22);

    $d->update(['ultima_lectura_en' => now()]);
    $this->getJson("/api/v1/dispositivos/{$d->id}")
        ->assertJsonPath('data.estado_conexion', 'en_linea')
        ->assertJsonPath('data.sin_lecturas', false)
        ->assertJsonPath('data.punto.porcentaje_llenado', 22);
});

test('las alertas del panel cubren sin reportar, bateria baja, lecturas invalidas y sensores por vincular', function () {
    $sin = Dispositivo::factory()->create(['intervalo_lectura_seg' => 1800, 'ultima_conexion_en' => now()->subHours(3)]);
    $bateria = Dispositivo::factory()->create(['ultima_conexion_en' => now()->subMinutes(5), 'bateria_voltaje' => 3.31]);
    $invalidas = Dispositivo::factory()->create(['ultima_conexion_en' => now()->subMinutes(5), 'lecturas_invalidas_seguidas' => 4]);
    $sano = Dispositivo::factory()->create(['ultima_conexion_en' => now()->subMinutes(5), 'bateria_voltaje' => 4.0, 'lecturas_invalidas_seguidas' => 2]);
    Dispositivo::factory()->pendiente()->create();

    $alertas = collect($this->getJson('/api/v1/kpis/alertas')->assertOk()->json('data'));
    $ids = $alertas->pluck('id');

    expect($ids)->toContain("sensor-sin-reporte-{$sin->punto_id}", "bateria-baja-{$bateria->punto_id}", "lecturas-invalidas-{$invalidas->id}")
        ->and($ids->filter(fn ($i) => str_contains($i, (string) $sano->punto_id) && str_starts_with($i, 'sensor'))->all())->toBe([])
        ->and($alertas->pluck('tipo'))->toContain('sensor_pendiente');
    expect($alertas->firstWhere('tipo', 'sensor_sin_reporte')['detalle'])->toContain('2 intervalos de 30 min');
});

test('un punto con sensor sin dispositivo conserva la alerta por umbral de 8 horas', function () {
    Punto::factory()->create(['sensor_codigo' => 'ESP-LEGADO', 'ultimo_heartbeat_iot' => now()->subHours(10)]);

    expect(collect($this->getJson('/api/v1/kpis/alertas')->json('data'))->pluck('tipo'))->toContain('sensor_sin_reporte');
});

test('se edita la calibracion y el intervalo con validacion', function () {
    $d = Dispositivo::factory()->create();
    $this->patchJson("/api/v1/dispositivos/{$d->id}", ['altura_contenedor_cm' => 10])->assertUnprocessable();
    $this->patchJson("/api/v1/dispositivos/{$d->id}", ['altura_contenedor_cm' => 150, 'intervalo_lectura_seg' => 900, 'tipo_sensor' => 'hc_sr04'])
        ->assertOk()
        ->assertJsonPath('data.altura_contenedor_cm', 150)
        ->assertJsonPath('data.intervalo_lectura_seg', 900)
        ->assertJsonPath('data.distancia_lleno_cm', 5)
        ->assertJsonPath('data.zona_ciega_cm', 2);
});

test('una lectura con la hora del sensor en el futuro se registra con la hora del servidor', function () {
    $punto = Punto::factory()->create();
    Dispositivo::factory()->conToken('eco_iot_f1')->create(['punto_id' => $punto->id, 'altura_contenedor_cm' => 120]);

    $this->withHeader('X-IoT-Token', 'eco_iot_f1')
        ->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 30, 'lectura_uuid' => 'f-1', 'medido_en' => now()->addYear()->toIso8601String()])
        ->assertCreated();

    expect($punto->fresh()->ultima_actualizacion_estado->lessThanOrEqualTo(now()->addMinute()))->toBeTrue();
});
