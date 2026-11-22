<?php

use App\Models\Administrador;
use App\Models\AuditLog;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\Punto;
use App\Models\Ruta;
use App\Models\TelemetriaGps;
use App\Models\User;
use App\Services\GpsTelemetryService;
use App\Services\OsmRouteOptimizerService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->adminUser = User::factory()->create(['role' => 'administrador']);
    $this->admin = Administrador::factory()->create(['user_id' => $this->adminUser->id]);

    $this->chofer = Chofer::factory()->create(['nombre_cifrado' => 'Pedro Conductor']);
    $this->camion = Camion::factory()->create([
        'estado_operativo' => 'disponible',
        'chofer_actual_id' => $this->chofer->id,
    ]);
});

test('genera una ruta optimizada con OSRM, filtra puntos alto/lleno y la entrega al chofer asignado', function () {
    $p1 = Punto::factory()->lleno()->create(['nombre' => 'Los Pablos', 'latitud' => -38.7180, 'longitud' => -72.6050]);
    $p2 = Punto::factory()->create(['nombre' => 'Av. Alemania', 'estado_llenado' => 'alto', 'latitud' => -38.7330, 'longitud' => -72.6100]);
    Punto::factory()->medio()->create(['nombre' => 'Plaza Centro']);
    Punto::factory()->bajo()->create(['nombre' => 'Santa Rosa']);

    $polilineaOsrm = OsmRouteOptimizerService::codificarPolilinea([
        [OsmRouteOptimizerService::PLANTA_LAT, OsmRouteOptimizerService::PLANTA_LNG],
        [-38.7330, -72.6100],
        [-38.7180, -72.6050],
        [OsmRouteOptimizerService::PLANTA_LAT, OsmRouteOptimizerService::PLANTA_LNG],
    ]);

    // OSRM invierte el orden: visita primero p2 (índice 2 -> waypoint_index 1) y luego p1 (índice 1 -> waypoint_index 2).
    Http::fake([
        'router.project-osrm.org/*' => Http::response([
            'code' => 'Ok',
            'waypoints' => [
                ['waypoint_index' => 0],
                ['waypoint_index' => 2],
                ['waypoint_index' => 1],
            ],
            'trips' => [
                [
                    'geometry' => $polilineaOsrm,
                    'distance' => 12450.0,
                    'duration' => 1320.0,
                    'legs' => [
                        ['distance' => 4200.0, 'duration' => 480.0],
                        ['distance' => 3100.0, 'duration' => 360.0],
                        ['distance' => 5150.0, 'duration' => 480.0],
                    ],
                ],
            ],
        ], 200),
    ]);

    // Antes de generar, el chofer no tiene ruta asignada.
    $this->actingAs($this->chofer->user, 'sanctum')
        ->getJson('/api/v1/chofer/ruta-activa')
        ->assertOk()
        ->assertJsonPath('data', null);

    $hoy = now()->format('Y-m-d');

    $respuesta = $this->actingAs($this->adminUser, 'sanctum')
        ->postJson('/api/v1/rutas/generar', [
            'camion_id' => $this->camion->id,
            'chofer_id' => $this->chofer->id,
            'fecha_programada' => $hoy,
        ])
        ->assertCreated()
        ->assertJsonPath('data.total_puntos_evaluados', 4)
        ->assertJsonPath('data.puntos_incluidos_count', 2)
        ->assertJsonPath('data.puntos_excluidos_count', 2)
        ->assertJsonPath('data.encoded_polyline', $polilineaOsrm)
        ->assertJsonPath('data.paradas.0.punto.id', $p2->id)
        ->assertJsonPath('data.paradas.0.distancia_tramo_km', 4.2)
        ->assertJsonPath('data.paradas.0.duracion_tramo_min', 8)
        ->assertJsonPath('data.paradas.1.punto.id', $p1->id)
        ->assertJsonPath('data.paradas.1.distancia_tramo_km', 3.1);

    expect(AuditLog::where('accion', 'route_generated')->where('user_id', $this->adminUser->id)->exists())->toBeTrue();

    // El chofer asignado ahora recibe su ruta real; otro chofer sigue con data: null.
    $rutaId = $respuesta->json('data.id');
    $this->actingAs($this->chofer->user, 'sanctum')
        ->getJson('/api/v1/chofer/ruta-activa')
        ->assertOk()
        ->assertJsonPath('data.id', $rutaId)
        ->assertJsonPath('data.paradas.0.punto.nombre', 'Av. Alemania');

    $otroChofer = Chofer::factory()->create();
    $this->actingAs($otroChofer->user, 'sanctum')
        ->getJson('/api/v1/chofer/ruta-activa')
        ->assertOk()
        ->assertJsonPath('data', null);

    // El admin puede listar las rutas de la fecha.
    $this->actingAs($this->adminUser, 'sanctum')
        ->getJson("/api/v1/rutas?fecha_programada={$hoy}")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('si OSRM falla o hace timeout aplica respaldo local automatico sin trabar el despacho', function () {
    $cerca = Punto::factory()->lleno()->create(['nombre' => 'Cerca', 'latitud' => -38.7580, 'longitud' => -72.5870]);
    $lejos = Punto::factory()->create(['nombre' => 'Lejos', 'estado_llenado' => 'alto', 'latitud' => -38.7180, 'longitud' => -72.6050]);

    Http::fake(fn () => throw new ConnectionException('OSRM timeout'));

    $respuesta = $this->actingAs($this->adminUser, 'sanctum')
        ->postJson('/api/v1/rutas/generar', [
            'camion_id' => $this->camion->id,
            'chofer_id' => $this->chofer->id,
            'fecha_programada' => now()->format('Y-m-d'),
        ])
        ->assertCreated()
        ->assertJsonPath('data.paradas.0.punto.id', $cerca->id)
        ->assertJsonPath('data.paradas.1.punto.id', $lejos->id);

    expect($respuesta->json('data.encoded_polyline'))->not->toBeEmpty()
        ->and((float) $respuesta->json('data.distancia_estimada_km'))->toBeGreaterThan(0)
        ->and((int) $respuesta->json('data.duracion_estimada_min'))->toBeGreaterThan(0);

    $log = AuditLog::where('accion', 'route_generated')->latest('id')->first();
    expect($log->detalles['fuente_ruteo'])->toBe('local');
});

test('ciclo completo del chofer: iniciar, sincronizar lote idempotente y finalizar ruta', function () {
    $punto = Punto::factory()->lleno()->create(['latitud' => -38.7400, 'longitud' => -72.5850]);
    Http::fake(['router.project-osrm.org/*' => Http::response([], 503)]);

    $rutaId = $this->actingAs($this->adminUser, 'sanctum')
        ->postJson('/api/v1/rutas/generar', [
            'camion_id' => $this->camion->id,
            'chofer_id' => $this->chofer->id,
            'fecha_programada' => now()->format('Y-m-d'),
        ])
        ->assertCreated()
        ->json('data.id');

    // Otro chofer no puede iniciar una ruta ajena.
    $otroChofer = Chofer::factory()->create();
    $this->actingAs($otroChofer->user, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/iniciar")
        ->assertForbidden();

    // Inicia ruta: cambia estado a en_curso y camión a en_ruta.
    $this->actingAs($this->chofer->user, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/iniciar")
        ->assertOk()
        ->assertJsonPath('data.estado', Ruta::ESTADO_EN_CURSO);

    expect($this->camion->fresh()->estado_operativo)->toBe('en_ruta');

    // Sincronización offline idempotente por sync_uuid.
    $uuid = Str::uuid()->toString();
    $lote = [
        'recolecciones' => [[
            'punto_id' => $punto->id,
            'sync_uuid' => $uuid,
            'hora_real_recoleccion' => now()->toIso8601String(),
            'observaciones' => 'Contenedor despejado',
        ]],
    ];

    $this->actingAs($this->chofer->user, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/sincronizar-lote", $lote)
        ->assertOk()
        ->assertJsonPath('data.paradas.0.estado_parada', 'recolectado')
        ->assertJsonPath('data.paradas.0.observaciones', 'Contenedor despejado');

    // Reenvío del mismo sync_uuid responde 200 sin error ni duplicado.
    $this->actingAs($this->chofer->user, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/sincronizar-lote", $lote)
        ->assertOk();

    expect($punto->fresh()->estado_llenado)->toBe(Punto::ESTADO_VACIO)
        ->and($punto->fresh()->porcentaje_llenado)->toBe(0)
        ->and($punto->fresh()->origen_actualizacion)->toBe(Punto::ORIGEN_RECOLECCION)
        ->and($punto->fresh()->estado_vigente_hasta)->not->toBeNull();

    // Finalizar marca completada y libera el camión a disponible.
    $this->actingAs($this->chofer->user, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/finalizar")
        ->assertOk()
        ->assertJsonPath('data.estado', Ruta::ESTADO_COMPLETADA);

    expect($this->camion->fresh()->estado_operativo)->toBe('disponible');
});

test('rechaza generar ruta si no hay puntos listos o el camion no esta disponible', function () {
    Punto::factory()->bajo()->create();

    $this->actingAs($this->adminUser, 'sanctum')
        ->postJson('/api/v1/rutas/generar', [
            'camion_id' => $this->camion->id,
            'chofer_id' => $this->chofer->id,
            'fecha_programada' => now()->format('Y-m-d'),
        ])
        ->assertUnprocessable();

    Punto::factory()->lleno()->create();
    $this->camion->update(['estado_operativo' => 'mantenimiento']);

    $this->actingAs($this->adminUser, 'sanctum')
        ->postJson('/api/v1/rutas/generar', [
            'camion_id' => $this->camion->id,
            'chofer_id' => $this->chofer->id,
            'fecha_programada' => now()->format('Y-m-d'),
        ])
        ->assertUnprocessable();
});

test('rechaza generar ruta con un camion que tiene otro chofer asignado', function () {
    Punto::factory()->lleno()->create();
    $otro = Chofer::factory()->create();

    $this->actingAs($this->adminUser, 'sanctum')
        ->postJson('/api/v1/rutas/generar', [
            'camion_id' => $this->camion->id,
            'chofer_id' => $otro->id,
            'fecha_programada' => now()->format('Y-m-d'),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'El camión seleccionado ya tiene otro chofer asignado.');

    expect(Ruta::count())->toBe(0);
});

test('rechaza generar ruta con un chofer que tiene otro camion asignado', function () {
    Punto::factory()->lleno()->create();
    $libre = Camion::factory()->create(['estado_operativo' => 'disponible', 'chofer_actual_id' => null]);

    $this->actingAs($this->adminUser, 'sanctum')
        ->postJson('/api/v1/rutas/generar', [
            'camion_id' => $libre->id,
            'chofer_id' => $this->chofer->id,
            'fecha_programada' => now()->format('Y-m-d'),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'El chofer seleccionado ya tiene otro camión asignado.');
});

test('la ruta parte de la ultima posicion GPS valida del chofer y si no hay de la planta', function () {
    Punto::factory()->lleno()->create();
    Http::fake(['router.project-osrm.org/*' => Http::response([], 500)]);
    $url = '/api/v1/rutas/generar';
    $payload = fn (string $fecha) => [
        'camion_id' => $this->camion->id,
        'chofer_id' => $this->chofer->id,
        'fecha_programada' => $fecha,
    ];

    $servicio = app(GpsTelemetryService::class);
    expect($servicio->origenParaChofer($this->chofer))->toBe([
        OsmRouteOptimizerService::PLANTA_LAT,
        OsmRouteOptimizerService::PLANTA_LNG,
    ]);

    $ruta = Ruta::create([
        'chofer_id' => $this->chofer->id,
        'camion_id' => $this->camion->id,
        'fecha_programada' => now()->subDay()->format('Y-m-d'),
        'estado' => Ruta::ESTADO_COMPLETADA,
        'total_puntos_evaluados' => 0,
        'puntos_incluidos_count' => 0,
        'puntos_excluidos_count' => 0,
        'distancia_estimada_km' => 0,
        'duracion_estimada_min' => 0,
        'encoded_polyline' => '',
    ]);
    $crear = fn (array $attrs) => TelemetriaGps::create($attrs + [
        'ruta_id' => $ruta->id,
        'camion_id' => $this->camion->id,
        'chofer_id' => $this->chofer->id,
        'ping_uuid' => (string) Str::uuid(),
        'velocidad_kmh' => 0,
        'es_simulado' => false,
    ]);
    $crear(['latitud' => -38.70, 'longitud' => -72.60, 'precision_m' => 10, 'capturado_en' => now()->subMinutes(20)]);
    // Ping más reciente pero impreciso: se ignora.
    $crear(['latitud' => -38.80, 'longitud' => -72.70, 'precision_m' => 400, 'capturado_en' => now()->subMinutes(5)]);
    // Ping antiguo (fuera de vigencia): se ignora.
    $crear(['latitud' => -38.90, 'longitud' => -72.90, 'precision_m' => 5, 'capturado_en' => now()->subDays(3)]);

    expect($servicio->origenParaChofer($this->chofer))->toBe([-38.70, -72.60]);

    $this->actingAs($this->adminUser, 'sanctum')
        ->postJson($url, $payload(now()->format('Y-m-d')))
        ->assertCreated();
});
