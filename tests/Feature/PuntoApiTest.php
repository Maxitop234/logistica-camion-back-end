<?php

use App\Models\Administrador;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\LecturaPunto;
use App\Models\Punto;
use App\Models\Ruta;
use App\Models\RutaPunto;
use App\Models\Sector;
use App\Models\SolicitudPunto;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'administrador']);
    Administrador::factory()->create(['user_id' => $this->admin->id]);
    $this->sector = Sector::factory()->dias([2, 4])->create(['nombre' => 'Las Quilas, centro']);
});

/**
 * @return array<string, mixed>
 */
function datosPuntoNuevo(array $extra = []): array
{
    return array_merge([
        'nombre' => 'Condominio Los Avellanos',
        'direccion' => 'Los Avellanos 1554, Temuco',
        'tipo_punto' => Punto::TIPO_CONDOMINIO,
        'latitud' => -38.7497840,
        'longitud' => -72.6096640,
    ], $extra);
}

test('filtrar por estado alto,lleno devuelve solo puntos listos y activos', function () {
    $alto = Punto::factory()->create(['sector_id' => $this->sector->id]);
    $lleno = Punto::factory()->lleno()->create(['sector_id' => $this->sector->id]);
    Punto::factory()->lleno()->create(['sector_id' => $this->sector->id, 'activo' => false]);
    Punto::factory()->medio()->create(['sector_id' => $this->sector->id]);
    Punto::factory()->sinMedicion()->create(['sector_id' => $this->sector->id]);

    $ids = collect($this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/puntos?estado_llenado=alto,lleno')
        ->assertOk()
        ->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$alto->id, $lleno->id])->sort()->values()->all());
});

test('filtra por sin medicion, sector, dispositivo, revision y vigencia, y rechaza valores invalidos', function () {
    $otroSector = Sector::factory()->create();
    $sinMedicion = Punto::factory()->sinMedicion()->create(['sector_id' => $this->sector->id]);
    $conSensor = Punto::factory()->create(['sector_id' => $otroSector->id, 'sensor_codigo' => 'ESP32-T1']);
    $porRevisar = Punto::factory()->create(['sector_id' => $otroSector->id, 'requiere_revision' => true]);

    $consultar = fn (string $query): array => collect($this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/v1/puntos?{$query}")
        ->assertOk()
        ->json('data'))->pluck('id')->sort()->values()->all();

    expect($consultar('estado_llenado=sin_medicion'))->toBe([$sinMedicion->id])
        ->and($consultar("sector_id={$this->sector->id}"))->toBe([$sinMedicion->id])
        ->and($consultar('con_dispositivo=1'))->toBe([$conSensor->id])
        ->and($consultar('requiere_revision=1'))->toBe([$porRevisar->id])
        ->and($consultar('estado_vigente=0'))->toBe([$sinMedicion->id]);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/puntos?estado_llenado=alto,desconocido')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['estado_llenado']);
});

test('un chofer recibe 403 en todos los endpoints de puntos del administrador', function () {
    $punto = Punto::factory()->create(['sector_id' => $this->sector->id]);
    $chofer = Chofer::factory()->create()->user;

    $peticiones = [
        ['getJson', '/api/v1/puntos'],
        ['postJson', '/api/v1/puntos'],
        ['getJson', "/api/v1/puntos/{$punto->id}"],
        ['putJson', "/api/v1/puntos/{$punto->id}"],
        ['deleteJson', "/api/v1/puntos/{$punto->id}"],
        ['postJson', "/api/v1/puntos/{$punto->id}/reactivar"],
        ['postJson', "/api/v1/puntos/{$punto->id}/sensor-token"],
        ['postJson', "/api/v1/puntos/{$punto->id}/simular-lectura"],
    ];

    foreach ($peticiones as [$metodo, $url]) {
        $this->actingAs($chofer, 'sanctum')->{$metodo}($url)->assertForbidden();
    }
});

test('coordenadas fuera de la zona de operacion responden 422 con un mensaje claro', function () {
    $respuesta = $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/puntos', datosPuntoNuevo([
            'sector_id' => $this->sector->id,
            'latitud' => -32.8415945,
            'longitud' => -70.6865994,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['latitud', 'longitud']);

    expect($respuesta->json('errors.latitud.0'))->toContain('fuera de la zona de operación');

    // Labranza (el oeste de la comuna) sí está dentro de la zona.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/puntos', datosPuntoNuevo([
            'sector_id' => $this->sector->id,
            'latitud' => -38.7638056,
            'longitud' => -72.7957222,
        ]))
        ->assertCreated();
});

test('un punto creado sin nivel queda sin medicion y nunca como vacio', function () {
    $respuesta = $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/puntos', datosPuntoNuevo(['sector_id' => $this->sector->id]))
        ->assertCreated()
        ->assertJsonPath('data.estado_llenado', null)
        ->assertJsonPath('data.porcentaje_llenado', null)
        ->assertJsonPath('data.estado_vigente', false)
        ->assertJsonPath('data.estado_vigente_hasta', null)
        ->assertJsonPath('data.sector_id', $this->sector->id)
        ->assertJsonPath('data.sector', 'Las Quilas, centro')
        ->assertJsonPath('data.requiere_revision', false)
        ->assertJsonPath('data.fuente_ubicacion', Punto::FUENTE_MANUAL);

    expect(LecturaPunto::where('punto_id', $respuesta->json('data.id'))->count())->toBe(0);
});

test('el sector se puede indicar por nombre y, si falta, se sugiere el mas cercano y queda para revision', function () {
    $lejano = Sector::factory()->create(['nombre' => 'Labranza']);
    Punto::factory()->create(['sector_id' => $lejano->id, 'latitud' => -38.7620, 'longitud' => -72.7600]);
    Punto::factory()->create(['sector_id' => $this->sector->id, 'latitud' => -38.7500, 'longitud' => -72.6100]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/puntos', datosPuntoNuevo([
            'sector' => 'labranza',
            'latitud' => -38.7623710,
            'longitud' => -72.7618770,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.sector_id', $lejano->id)
        ->assertJsonPath('data.requiere_revision', false);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/puntos', datosPuntoNuevo(['nombre' => 'Punto sin sector']))
        ->assertCreated()
        ->assertJsonPath('data.sector_id', $this->sector->id)
        ->assertJsonPath('data.requiere_revision', true);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/puntos', datosPuntoNuevo(['sector' => 'Centro']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sector']);
});

test('el ajuste manual del porcentaje deriva el estado, queda como manual y vigente hasta el siguiente dia de recoleccion', function () {
    // Lunes 23-11-2026 09:00 en Chile; el sector recolecta martes y jueves.
    Carbon::setTestNow(Carbon::parse('2026-11-23 12:00:00', 'UTC'));
    $punto = Punto::factory()->sinMedicion()->create(['sector_id' => $this->sector->id]);

    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/v1/puntos/{$punto->id}", ['porcentaje_llenado' => 75])
        ->assertOk()
        ->assertJsonPath('data.estado_llenado', 'alto')
        ->assertJsonPath('data.porcentaje_llenado', 75)
        ->assertJsonPath('data.origen_actualizacion', Punto::ORIGEN_MANUAL)
        ->assertJsonPath('data.estado_vigente', true)
        ->assertJsonPath('data.estado_vigente_hasta', '2026-11-25T02:59:59+00:00');

    // Solo el estado, sin porcentaje.
    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/v1/puntos/{$punto->id}", ['estado_llenado' => 'bajo'])
        ->assertOk()
        ->assertJsonPath('data.estado_llenado', 'bajo')
        ->assertJsonPath('data.porcentaje_llenado', null)
        ->assertJsonPath('data.origen_actualizacion', Punto::ORIGEN_MANUAL);

    Carbon::setTestNow();
});

test('mover un punto actualiza sus coordenadas, su ubicacion PostGIS y la deja como manual', function () {
    $punto = Punto::factory()->create([
        'sector_id' => $this->sector->id,
        'fuente_ubicacion' => Punto::FUENTE_COORDENADAS,
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/v1/puntos/{$punto->id}", ['latitud' => -38.7427702, 'longitud' => -72.5882028])
        ->assertOk()
        ->assertJsonPath('data.latitud', -38.7427702)
        ->assertJsonPath('data.longitud', -72.5882028)
        ->assertJsonPath('data.fuente_ubicacion', Punto::FUENTE_MANUAL);

    $ubicacion = DB::selectOne(
        'SELECT ST_Y(ubicacion::geometry) AS lat, ST_X(ubicacion::geometry) AS lng FROM puntos WHERE id = ?',
        [$punto->id],
    );
    expect(round((float) $ubicacion->lat, 7))->toBe(-38.7427702)
        ->and(round((float) $ubicacion->lng, 7))->toBe(-72.5882028);
});

test('no se puede desactivar un punto que esta en una ruta en curso', function () {
    $punto = Punto::factory()->create(['sector_id' => $this->sector->id]);
    $chofer = Chofer::factory()->create();
    $ruta = Ruta::create([
        'camion_id' => Camion::factory()->create()->id,
        'chofer_id' => $chofer->id,
        'fecha_programada' => now()->toDateString(),
        'estado' => Ruta::ESTADO_EN_CURSO,
    ]);
    RutaPunto::create([
        'ruta_id' => $ruta->id,
        'punto_id' => $punto->id,
        'orden_parada' => 1,
        'estado_llenado_inicial' => Punto::ESTADO_ALTO,
        'estado_parada' => RutaPunto::ESTADO_PENDIENTE,
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/puntos/{$punto->id}")
        ->assertStatus(409);

    $ruta->update(['estado' => Ruta::ESTADO_COMPLETADA]);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/puntos/{$punto->id}")
        ->assertOk()
        ->assertJsonPath('data.activo', false);
});

test('aprobar la propuesta de un chofer crea el punto sin medicion en el sector mas cercano y para revision', function () {
    Punto::factory()->create(['sector_id' => $this->sector->id, 'latitud' => -38.7500, 'longitud' => -72.6100]);
    $chofer = Chofer::factory()->create();
    $solicitud = SolicitudPunto::create([
        'chofer_id' => $chofer->id,
        'nombre' => 'Punto propuesto',
        'direccion' => 'Río Valdivia 1646, Temuco',
        'tipo_punto' => Punto::TIPO_CALLE,
        'latitud' => -38.7505270,
        'longitud' => -72.6078060,
        'estado' => SolicitudPunto::ESTADO_PENDIENTE,
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/notificaciones/puntos/{$solicitud->id}/aprobar")
        ->assertOk();

    $punto = Punto::findOrFail($solicitud->fresh()->punto_creado_id);
    expect($punto->estado_llenado)->toBeNull()
        ->and($punto->porcentaje_llenado)->toBeNull()
        ->and($punto->sector_id)->toBe($this->sector->id)
        ->and($punto->requiere_revision)->toBeTrue();
});
