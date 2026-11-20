<?php

use App\Models\Administrador;
use App\Models\AuditLog;
use App\Models\Chofer;
use App\Models\LecturaPunto;
use App\Models\NotificacionChofer;
use App\Models\Punto;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'administrador']);
    Administrador::factory()->create([
        'user_id' => $this->admin->id,
        'nombre_completo' => 'María Administradora',
    ]);

    $this->chofer = Chofer::factory()->create(['nombre_cifrado' => 'Pedro Chofer']);
    $this->choferUser = $this->chofer->user;
});

test('crud completo de puntos por el administrador con baja logica, reactivacion e historial', function () {
    // Chofer no puede usar CRUD admin.
    $this->actingAs($this->choferUser, 'sanctum')
        ->getJson('/api/v1/puntos')
        ->assertForbidden();

    // Crear punto.
    $creado = $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/puntos', [
            'nombre' => 'Condominio Araucaria Norte',
            'direccion' => 'Av. Alemania 1200, Temuco',
            'tipo_punto' => 'condominio',
            'latitud' => -38.7312,
            'longitud' => -72.6125,
            'porcentaje_llenado' => 78,
            'altura_contenedor_cm' => 140,
        ])
        ->assertCreated()
        ->assertJsonPath('data.nombre', 'Condominio Araucaria Norte')
        ->assertJsonPath('data.tipo_punto', 'condominio')
        ->assertJsonPath('data.estado_llenado', 'alto')
        ->assertJsonPath('data.porcentaje_llenado', 78)
        ->assertJsonPath('data.altura_contenedor_cm', 140)
        ->assertJsonPath('data.origen_actualizacion', 'manual')
        ->json('data.id');

    // Detalle trae lecturas registradas.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/v1/puntos/{$creado}")
        ->assertOk()
        ->assertJsonCount(1, 'data.lecturas');

    // Editar ubicación y nivel manual -> agrega nueva lectura y reclasifica.
    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/v1/puntos/{$creado}", [
            'direccion' => 'Av. Alemania 1250, Temuco',
            'porcentaje_llenado' => 95,
        ])
        ->assertOk()
        ->assertJsonPath('data.direccion', 'Av. Alemania 1250, Temuco')
        ->assertJsonPath('data.estado_llenado', 'lleno')
        ->assertJsonCount(2, 'data.lecturas');

    // Baja lógica y reactivación.
    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/puntos/{$creado}")
        ->assertOk()
        ->assertJsonPath('data.activo', false);

    // Chofer solo ve puntos activos.
    $this->actingAs($this->choferUser, 'sanctum')
        ->getJson('/api/v1/chofer/puntos')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/puntos/{$creado}/reactivar")
        ->assertOk()
        ->assertJsonPath('data.activo', true);

    $this->actingAs($this->choferUser, 'sanctum')
        ->getJson('/api/v1/chofer/puntos')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect(AuditLog::where('accion', 'punto_creado')->exists())->toBeTrue()
        ->and(AuditLog::where('accion', 'punto_desactivado')->exists())->toBeTrue()
        ->and(AuditLog::where('accion', 'punto_reactivado')->exists())->toBeTrue();
});

test('ingesta ESP32 autentica por token de sensor, calcula porcentaje por distancia_cm y respeta idempotencia', function () {
    $punto = Punto::factory()->create([
        'tipo_punto' => 'calle',
        'altura_contenedor_cm' => 120.0,
        'porcentaje_llenado' => 10,
        'estado_llenado' => 'vacio',
    ]);

    // Sin token o token inválido -> 401.
    $this->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 24])
        ->assertUnauthorized();

    $this->withToken('token_invalido_123')
        ->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 24])
        ->assertUnauthorized();

    // Admin genera token de sensor (devuelto una sola vez, guardado como hash).
    $tokenResp = $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/puntos/{$punto->id}/sensor-token")
        ->assertOk()
        ->assertJsonPath('data.endpoint_iot', '/api/v1/iot/lecturas');

    $token = $tokenResp->json('data.token');
    expect($token)->toStartWith('eco_iot_');
    expect($punto->fresh()->sensor_token_hash)->toBe(Punto::hashSensorToken($token));

    // Fórmula ultrasónica: altura 120 cm, distancia 24 cm -> (120 - 24) / 120 * 100 = 80 % -> alto.
    $uuid = 'esp32-lectura-001';
    $this->withToken($token)
        ->postJson('/api/v1/iot/lecturas', [
            'distancia_cm' => 24,
            'bateria_voltaje' => 3.88,
            'lectura_uuid' => $uuid,
            'medido_en' => '2026-11-20T18:00:00Z',
        ])
        ->assertCreated()
        ->assertJsonPath('duplicada', false)
        ->assertJsonPath('data.porcentaje_llenado', 80)
        ->assertJsonPath('data.estado_llenado', 'alto')
        ->assertJsonPath('data.origen_actualizacion', 'sensor_iot')
        ->assertJsonPath('data.bateria_voltaje', 3.88);

    expect($punto->fresh()->ultimo_heartbeat_iot)->not->toBeNull();
    expect(LecturaPunto::where('punto_id', $punto->id)->count())->toBe(1);

    // Reintento offline con el mismo lectura_uuid -> 200 duplicada sin insertar segunda fila.
    $this->withToken($token)
        ->postJson('/api/v1/iot/lecturas', [
            'distancia_cm' => 24,
            'bateria_voltaje' => 3.88,
            'lectura_uuid' => $uuid,
        ])
        ->assertOk()
        ->assertJsonPath('duplicada', true);

    expect(LecturaPunto::where('punto_id', $punto->id)->count())->toBe(1);

    // Simulación IoT desde el admin usa exactamente el mismo cálculo: distancia 6 cm -> 95 % -> lleno.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/puntos/{$punto->id}/simular-lectura", [
            'distancia_cm' => 6,
            'bateria_voltaje' => 3.75,
        ])
        ->assertCreated()
        ->assertJsonPath('data.porcentaje_llenado', 95)
        ->assertJsonPath('data.estado_llenado', 'lleno');

    // Si el punto está inactivo, el ESP32 recibe 403.
    $punto->update(['activo' => false]);
    $this->withToken($token)
        ->postJson('/api/v1/iot/lecturas', ['porcentaje_llenado' => 50])
        ->assertForbidden();
});

test('chofer propone nuevo punto y administrador puede aprobar creando el punto o rechazar con motivo', function () {
    // Chofer envía propuesta 1 y propuesta 2.
    $s1 = $this->actingAs($this->choferUser, 'sanctum')
        ->postJson('/api/v1/chofer/solicitudes-punto', [
            'nombre' => 'Condominio Los Robles',
            'direccion' => 'Av. Andes 450, Temuco',
            'tipo_punto' => 'condominio',
            'latitud' => -38.7388,
            'longitud' => -72.6012,
            'observacion' => 'Vecinos solicitan contenedor de reciclaje.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente')
        ->json('data.id');

    $s2 = $this->actingAs($this->choferUser, 'sanctum')
        ->postJson('/api/v1/chofer/solicitudes-punto', [
            'nombre' => 'Esquina Sin Acceso',
            'direccion' => 'Pasaje Estrecho 10',
            'tipo_punto' => 'calle',
            'latitud' => -38.7410,
            'longitud' => -72.5990,
        ])
        ->assertCreated()
        ->json('data.id');

    // Admin ve 2 propuestas pendientes en sus notificaciones.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/notificaciones/puntos')
        ->assertOk()
        ->assertJsonPath('meta.pendientes_count', 2);

    // Admin aprueba propuesta 1 -> crea Punto activo y notifica al chofer.
    $puntoCreadoId = $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/notificaciones/puntos/{$s1}/aprobar")
        ->assertOk()
        ->assertJsonPath('data.estado', 'aprobada')
        ->json('data.punto_creado_id');

    expect(Punto::find($puntoCreadoId))->not->toBeNull()
        ->and(Punto::find($puntoCreadoId)->nombre)->toBe('Condominio Los Robles');

    // Rechazar propuesta 2 exige motivo obligatorio.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/notificaciones/puntos/{$s2}/rechazar", ['motivo_rechazo' => 'no'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['motivo_rechazo']);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/notificaciones/puntos/{$s2}/rechazar", [
            'motivo_rechazo' => 'El pasaje no permite ingreso de camión recolector.',
        ])
        ->assertOk()
        ->assertJsonPath('data.estado', 'rechazada');

    $tipos = NotificacionChofer::where('chofer_id', $this->chofer->id)->pluck('tipo')->all();
    expect($tipos)->toContain(NotificacionChofer::TIPO_PUNTO_APROBADO)
        ->and($tipos)->toContain(NotificacionChofer::TIPO_PUNTO_RECHAZADO);
});
