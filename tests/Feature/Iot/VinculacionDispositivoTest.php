<?php

use App\Models\Administrador;
use App\Models\AuditLog;
use App\Models\CodigoVinculacion;
use App\Models\Dispositivo;
use App\Models\Punto;
use App\Models\Sector;
use App\Models\User;
use App\Services\Iot\VinculacionDeDispositivos;

beforeEach(function () {
    config(['app.demo_mode' => false]);
    $this->admin = User::factory()->create(['role' => 'administrador']);
    Administrador::factory()->create(['user_id' => $this->admin->id]);
    $this->etiqueta = app(VinculacionDeDispositivos::class)->generarCodigos(1, 'prueba')[0];
});

function anunciar(object $test, array $etiqueta, array $extra = [])
{
    return $test->postJson('/api/v1/iot/vinculacion/anunciar', [
        'codigo_hardware' => $etiqueta['codigo_hardware'],
        'codigo_vinculacion' => $etiqueta['codigo'],
        'firmware_version' => '1.0.0',
        'conectividad' => 'wifi',
        ...$extra,
    ]);
}

function consultarEstado(object $test, array $etiqueta)
{
    return $test->postJson('/api/v1/iot/vinculacion/estado', [
        'codigo_hardware' => $etiqueta['codigo_hardware'],
        'codigo_vinculacion' => $etiqueta['codigo'],
    ]);
}

test('un sensor se anuncia con el codigo de su etiqueta y queda pendiente', function () {
    anunciar($this, $this->etiqueta)->assertCreated()->assertJsonPath('estado', 'pendiente');

    $d = Dispositivo::firstOrFail();
    expect($d->estado)->toBe('pendiente')->and($d->punto_id)->toBeNull()->and($d->token_hash)->toBeNull()
        ->and($d->firmware_version)->toBe('1.0.0');

    // Repetir el anuncio no duplica.
    anunciar($this, $this->etiqueta)->assertOk();
    expect(Dispositivo::count())->toBe(1);

    // El token no existe aun: la consulta devuelve estado pendiente sin token.
    consultarEstado($this, $this->etiqueta)->assertOk()->assertJsonPath('estado', 'pendiente')->assertJsonPath('token', null);
});

test('un codigo de etiqueta incorrecto o un hardware desconocido responden igual y no crean nada', function () {
    $mal = ['codigo_hardware' => $this->etiqueta['codigo_hardware'], 'codigo' => 'AAAA-AAAA-AAAA'];
    $a = anunciar($this, $mal)->assertForbidden()->json('message');
    $b = anunciar($this, ['codigo_hardware' => 'NO-EXISTE', 'codigo' => $this->etiqueta['codigo']])->assertForbidden()->json('message');

    expect($a)->toBe($b)->and(Dispositivo::count())->toBe(0);
    consultarEstado($this, $mal)->assertForbidden();
});

test('el codigo de etiqueta tolera minusculas y sin guiones', function () {
    $suelto = ['codigo_hardware' => $this->etiqueta['codigo_hardware'], 'codigo' => strtolower(str_replace('-', '', $this->etiqueta['codigo']))];
    anunciar($this, $suelto)->assertCreated();
});

test('la vinculacion publica tiene limite de intentos', function () {
    $mal = ['codigo_hardware' => $this->etiqueta['codigo_hardware'], 'codigo' => 'AAAA-AAAA-AAAA'];
    for ($i = 0; $i < 10; $i++) {
        anunciar($this, $mal)->assertForbidden();
    }
    anunciar($this, $mal)->assertStatus(429);
    // Tampoco sirve el codigo correcto mientras dure el bloqueo.
    anunciar($this, $this->etiqueta)->assertStatus(429);
});

test('el admin aprueba, elige el punto y el sensor retira su token una sola vez', function () {
    anunciar($this, $this->etiqueta)->assertCreated();
    $punto = Punto::factory()->create();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/dispositivos/aprobar', [
            'codigo_hardware' => $this->etiqueta['codigo_hardware'],
            'codigo_vinculacion' => $this->etiqueta['codigo'],
            'punto_id' => $punto->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.estado', 'activo')
        ->assertJsonPath('data.punto.id', $punto->id)
        ->assertJsonPath('data.token_por_retirar', true)
        ->assertJsonMissingPath('data.token');

    $primera = consultarEstado($this, $this->etiqueta)->assertOk()->assertJsonPath('estado', 'activo');
    $token = $primera->json('token');
    expect($token)->toBeString()->toStartWith('eco_iot_');

    // Segunda consulta: ya no se entrega.
    consultarEstado($this, $this->etiqueta)->assertOk()->assertJsonPath('token', null);
    $d = Dispositivo::firstOrFail();
    expect($d->token_pendiente_cifrado)->toBeNull()->and($d->token_entregado_en)->not->toBeNull()
        ->and($d->token_hash)->toBe(Dispositivo::hashToken($token))
        ->and($punto->fresh()->sensor_codigo)->toBe($this->etiqueta['codigo_hardware']);

    // El detalle del admin nunca incluye el token.
    $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/dispositivos/{$d->id}")
        ->assertOk()->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.token_hash');

    // Y con ese token el sensor ya envia lecturas.
    $this->withHeader('X-IoT-Token', $token)->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 60])->assertCreated();

    expect(AuditLog::where('accion', 'dispositivo_vinculado')->count())->toBe(1);
    expect(AuditLog::where('accion', 'dispositivo_vinculado')->first()->detalles)->not->toHaveKey('token');
});

test('aprobar exige el codigo correcto, un sensor anunciado y un punto libre', function () {
    $this->actingAs($this->admin, 'sanctum');
    $punto = Punto::factory()->create();
    $cuerpo = ['codigo_hardware' => $this->etiqueta['codigo_hardware'], 'codigo_vinculacion' => $this->etiqueta['codigo'], 'punto_id' => $punto->id];

    // Aun no se anuncio.
    $this->postJson('/api/v1/dispositivos/aprobar', $cuerpo)->assertNotFound();

    anunciar($this, $this->etiqueta);
    $this->postJson('/api/v1/dispositivos/aprobar', [...$cuerpo, 'codigo_vinculacion' => 'ZZZZ-ZZZZ-ZZZZ'])->assertForbidden();

    // Punto con otro sensor: 422.
    Dispositivo::factory()->create(['punto_id' => $punto->id]);
    $this->postJson('/api/v1/dispositivos/aprobar', $cuerpo)->assertUnprocessable()->assertJsonValidationErrors('punto_id');
    expect(Dispositivo::where('estado', 'pendiente')->count())->toBe(1);
});

test('el admin puede crear el punto al aprobar', function () {
    anunciar($this, $this->etiqueta);
    $sector = Sector::factory()->create();

    $id = $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/dispositivos/aprobar', [
            'codigo_hardware' => $this->etiqueta['codigo_hardware'],
            'codigo_vinculacion' => $this->etiqueta['codigo'],
            'punto' => [
                'nombre' => 'Condominio Nuevo', 'direccion' => 'Av. Alemania 100, Temuco', 'tipo_punto' => 'condominio',
                'latitud' => -38.73, 'longitud' => -72.60, 'sector_id' => $sector->id,
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.punto.nombre', 'Condominio Nuevo')
        ->json('data.punto.id');

    expect(Punto::find($id)->sector_id)->toBe($sector->id);
});

test('solo el administrador gestiona sensores', function () {
    $chofer = User::factory()->create(['role' => 'chofer']);
    $d = Dispositivo::factory()->create();

    $this->actingAs($chofer, 'sanctum')->getJson('/api/v1/dispositivos')->assertForbidden();
    $this->actingAs($chofer, 'sanctum')->postJson("/api/v1/dispositivos/{$d->id}/rotar-token")->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/dispositivos')->assertUnauthorized();
});

test('desvincular, mover, desactivar, reactivar y rotar token quedan auditados', function () {
    $this->actingAs($this->admin, 'sanctum');
    $origen = Punto::factory()->create();
    $destino = Punto::factory()->create();
    $d = Dispositivo::factory()->conToken('eco_iot_viejo')->create(['punto_id' => $origen->id]);
    $origen->update(['sensor_codigo' => $d->codigo_hardware]);

    // Mover a un punto ocupado falla; a uno libre funciona y traslada el codigo.
    $ocupado = Punto::factory()->create();
    Dispositivo::factory()->create(['punto_id' => $ocupado->id]);
    $this->postJson("/api/v1/dispositivos/{$d->id}/mover", ['punto_id' => $ocupado->id])->assertUnprocessable();
    $this->postJson("/api/v1/dispositivos/{$d->id}/mover", ['punto_id' => $destino->id])->assertOk()->assertJsonPath('data.punto.id', $destino->id);
    expect($origen->fresh()->sensor_codigo)->toBeNull()->and($destino->fresh()->sensor_codigo)->toBe($d->codigo_hardware);

    $this->postJson("/api/v1/dispositivos/{$d->id}/desvincular")->assertOk()->assertJsonPath('data.punto', null);
    expect($destino->fresh()->sensor_codigo)->toBeNull();

    // Rotar invalida el token anterior.
    $this->postJson("/api/v1/dispositivos/{$d->id}/rotar-token")->assertOk()->assertJsonPath('data.token_por_retirar', true);
    $this->withHeader('X-IoT-Token', 'eco_iot_viejo')->postJson('/api/v1/iot/lecturas', ['distancia_cm' => 60])->assertUnauthorized();

    $this->postJson("/api/v1/dispositivos/{$d->id}/desactivar")->assertOk()->assertJsonPath('data.estado', 'desactivado');
    $this->postJson("/api/v1/dispositivos/{$d->id}/rotar-token")->assertStatus(409);
    $this->postJson("/api/v1/dispositivos/{$d->id}/reactivar")->assertOk()->assertJsonPath('data.estado', 'activo');

    expect(AuditLog::whereIn('accion', [
        'dispositivo_movido', 'dispositivo_desvinculado', 'token_iot_rotado', 'dispositivo_desactivado', 'dispositivo_reactivado',
    ])->count())->toBe(5);
});

test('el comando genera etiquetas por lote sin guardar el codigo en claro', function () {
    $archivo = tempnam(sys_get_temp_dir(), 'etq');
    $this->artisan('iot:generar-etiquetas', ['cantidad' => 3, '--lote' => 'piloto', '--prefijo' => 'TST', '--salida' => $archivo])->assertSuccessful();

    $lineas = array_map('str_getcsv', array_filter(explode("\n", file_get_contents($archivo)), 'strlen'));
    unlink($archivo);
    expect($lineas[0])->toBe(['codigo_hardware', 'codigo', 'qr'])->and($lineas)->toHaveCount(4)
        ->and($lineas[1][0])->toBe('TST-0001')->and($lineas[1][1])->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/');

    $hash = CodigoVinculacion::where('codigo_hardware', 'TST-0001')->value('codigo_hash');
    expect($hash)->not->toContain($lineas[1][1])->and(CodigoVinculacion::where('lote', 'piloto')->count())->toBe(3);
});
