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
use Illuminate\Http\Client\Request;
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

/**
 * Tres puntos al norponiente de la planta. Generada desde la planta (sin GPS del chofer
 * y con OSRM caído), la ruta queda planificada en el orden C, B, A.
 *
 * @return array{ruta_id: int, a: Punto, b: Punto, c: Punto}
 */
function generarRutaParaOrdenarAlIniciar(object $t): array
{
    $a = Punto::factory()->lleno()->create(['nombre' => 'A', 'latitud' => -38.7305, 'longitud' => -72.6005]);
    $b = Punto::factory()->lleno()->create(['nombre' => 'B', 'latitud' => -38.7350, 'longitud' => -72.6050]);
    $c = Punto::factory()->lleno()->create(['nombre' => 'C', 'latitud' => -38.7450, 'longitud' => -72.6150]);

    $respuesta = $t->actingAs($t->adminUser, 'sanctum')
        ->postJson('/api/v1/rutas/generar', [
            'camion_id' => $t->camion->id,
            'chofer_id' => $t->chofer->id,
            'fecha_programada' => now()->format('Y-m-d'),
        ])
        ->assertCreated()
        ->assertJsonPath('data.paradas.0.punto.id', $c->id)
        ->assertJsonPath('data.paradas.1.punto.id', $b->id)
        ->assertJsonPath('data.paradas.2.punto.id', $a->id);

    return ['ruta_id' => $respuesta->json('data.id'), 'a' => $a, 'b' => $b, 'c' => $c];
}

test('al iniciar, la ruta parte por la parada más cercana al chofer y sigue por cercanía', function () {
    Http::fake(fn () => throw new ConnectionException('OSRM timeout'));
    ['ruta_id' => $rutaId, 'a' => $a, 'b' => $b, 'c' => $c] = generarRutaParaOrdenarAlIniciar($this);
    $estimadaAlGenerar = (float) Ruta::findOrFail($rutaId)->distancia_estimada_km;

    // El chofer está junto a A: el orden planificado desde la planta (C, B, A) se invierte.
    $respuesta = $this->actingAs($this->chofer->user, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/iniciar", ['latitud' => -38.7300, 'longitud' => -72.6000])
        ->assertOk()
        ->assertJsonPath('data.estado', Ruta::ESTADO_EN_CURSO)
        ->assertJsonPath('data.paradas.0.punto.id', $a->id)
        ->assertJsonPath('data.paradas.0.orden_parada', 1)
        ->assertJsonPath('data.paradas.1.punto.id', $b->id)
        ->assertJsonPath('data.paradas.2.punto.id', $c->id)
        ->assertJsonPath('data.paradas.2.orden_parada', 3);

    // El primer tramo es el corto del chofer a A, no el de la planta a C.
    expect((float) $respuesta->json('data.paradas.0.distancia_tramo_km'))->toBeLessThan(0.2)
        ->and((float) $respuesta->json('data.distancia_estimada_km'))->not->toBe($estimadaAlGenerar);

    // El trazado parte en el chofer y termina en la planta.
    $trazado = OsmRouteOptimizerService::decodificarPolilinea($respuesta->json('data.encoded_polyline'));
    expect($trazado[0])->toBe([-38.73, -72.6])
        ->and(end($trazado))->toBe([OsmRouteOptimizerService::PLANTA_LAT, OsmRouteOptimizerService::PLANTA_LNG]);

    $log = AuditLog::where('accion', 'ruta_iniciada')->latest('id')->first();
    expect($log->detalles['orden_desde'])->toBe('posicion_al_iniciar')
        ->and($log->detalles['fuente_ruteo'])->toBe('local')
        ->and($log->detalles)->not->toHaveKeys(['latitud', 'longitud']);
});

test('con OSRM la cercanía es por calles: lo cercano en línea recta pero sin paso directo queda al final', function () {
    $chofer = [-38.7300, -72.6000];
    $a = [-38.7305, -72.6005];

    // Matriz por calles: distancia aproximada, salvo A, al otro lado del río (+10 km desde cualquier lado).
    Http::fake(function (Request $peticion) use ($a) {
        $url = $peticion->url();
        if (str_contains($url, '/table/v1/driving/')) {
            $coordenadas = array_map(
                fn (string $par): array => array_map('floatval', array_reverse(explode(',', $par))),
                explode(';', Str::before(Str::after($url, '/table/v1/driving/'), '?')),
            );
            $distancias = [];
            foreach ($coordenadas as $i => $desde) {
                foreach ($coordenadas as $j => $hasta) {
                    $cruzaRio = ($desde === $a) !== ($hasta === $a);
                    $distancias[$i][$j] = hypot($desde[0] - $hasta[0], $desde[1] - $hasta[1]) * 100000 + ($cruzaRio ? 10000 : 0);
                }
            }

            return Http::response(['code' => 'Ok', 'distances' => $distancias]);
        }
        if (str_contains($url, '/route/v1/driving/')) {
            return Http::response(['code' => 'Ok', 'routes' => [[
                'geometry' => 'trazado_osrm',
                'distance' => 15300.0,
                'duration' => 1500.0,
                'legs' => [
                    ['distance' => 700.0, 'duration' => 120.0],
                    ['distance' => 1600.0, 'duration' => 240.0],
                    ['distance' => 11000.0, 'duration' => 900.0],
                    ['distance' => 2000.0, 'duration' => 240.0],
                ],
            ]]]);
        }

        return Http::response([], 503);
    });

    ['ruta_id' => $rutaId, 'a' => $puntoA, 'b' => $b, 'c' => $c] = generarRutaParaOrdenarAlIniciar($this);

    $this->actingAs($this->chofer->user, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/iniciar", ['latitud' => $chofer[0], 'longitud' => $chofer[1]])
        ->assertOk()
        ->assertJsonPath('data.paradas.0.punto.id', $b->id)
        ->assertJsonPath('data.paradas.0.distancia_tramo_km', 0.7)
        ->assertJsonPath('data.paradas.0.duracion_tramo_min', 2)
        ->assertJsonPath('data.paradas.1.punto.id', $c->id)
        ->assertJsonPath('data.paradas.2.punto.id', $puntoA->id)
        ->assertJsonPath('data.paradas.2.distancia_tramo_km', 11)
        ->assertJsonPath('data.encoded_polyline', 'trazado_osrm')
        ->assertJsonPath('data.distancia_estimada_km', 15.3)
        ->assertJsonPath('data.duracion_estimada_min', 25 + 3 * 4);

    // El trazado se pide en el orden decidido: chofer → B → C → A → planta.
    Http::assertSent(fn (Request $peticion): bool => str_contains(
        $peticion->url(),
        '/route/v1/driving/-72.600000,-38.730000;-72.605000,-38.735000;-72.615000,-38.745000;-72.600500,-38.730500;-72.586500,-38.765500',
    ));

    expect(AuditLog::where('accion', 'ruta_iniciada')->latest('id')->first()->detalles['fuente_ruteo'])->toBe('osrm');
});

test('sin posición al iniciar ordena desde la última posición conocida o la planta y valida las coordenadas', function () {
    Http::fake(['router.project-osrm.org/*' => Http::response([], 503)]);
    ['ruta_id' => $rutaId, 'c' => $c] = generarRutaParaOrdenarAlIniciar($this);

    $this->actingAs($this->chofer->user, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/iniciar", ['latitud' => -38.73])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('longitud');
    $this->actingAs($this->chofer->user, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/iniciar", ['latitud' => -138.73, 'longitud' => -72.6])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('latitud');
    expect(Ruta::findOrFail($rutaId)->estado)->toBe(Ruta::ESTADO_PLANIFICADA);

    // Sin GPS reciente del chofer, la parada más cercana a la planta va primero.
    $this->actingAs($this->chofer->user, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/iniciar")
        ->assertOk()
        ->assertJsonPath('data.paradas.0.punto.id', $c->id);

    expect(AuditLog::where('accion', 'ruta_iniciada')->latest('id')->first()->detalles['orden_desde'])->toBe('planta');
});

function generarRutaParaCancelar(object $t): int
{
    Http::fake(['router.project-osrm.org/*' => Http::response([], 503)]);
    Punto::factory()->lleno()->create(['latitud' => -38.7400, 'longitud' => -72.5850]);
    Punto::factory()->lleno()->create(['latitud' => -38.7420, 'longitud' => -72.5870]);

    return $t->actingAs($t->adminUser, 'sanctum')
        ->postJson('/api/v1/rutas/generar', [
            'camion_id' => $t->camion->id,
            'chofer_id' => $t->chofer->id,
            'fecha_programada' => now()->format('Y-m-d'),
        ])
        ->assertCreated()
        ->json('data.id');
}

test('el admin cancela una ruta planificada con motivo y queda auditado', function () {
    $rutaId = generarRutaParaCancelar($this);

    $this->actingAs($this->adminUser, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/cancelar", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo');

    $this->actingAs($this->adminUser, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/cancelar", ['motivo' => 'Camión con falla'])
        ->assertOk()
        ->assertJsonPath('data.estado', Ruta::ESTADO_CANCELADA)
        ->assertJsonPath('data.motivo_cancelacion', 'Camión con falla');

    expect(AuditLog::where('accion', 'ruta_cancelada')->count())->toBe(1);

    // El chofer ya no tiene ruta activa y recibe el aviso de cancelación.
    $this->actingAs($this->chofer->user, 'sanctum')
        ->getJson('/api/v1/chofer/ruta-activa')
        ->assertOk()
        ->assertJsonPath('data', null)
        ->assertJsonPath('cancelada.id', $rutaId)
        ->assertJsonPath('cancelada.motivo', 'Camión con falla');

    // Se puede generar otra ruta con los mismos puntos.
    $this->actingAs($this->adminUser, 'sanctum')
        ->postJson('/api/v1/rutas/generar', [
            'camion_id' => $this->camion->id,
            'chofer_id' => $this->chofer->id,
            'fecha_programada' => now()->format('Y-m-d'),
        ])
        ->assertCreated()
        ->assertJsonCount(2, 'data.paradas');
});

test('cancelar una ruta en curso conserva lo recolectado y libera el camión', function () {
    $rutaId = generarRutaParaCancelar($this);
    $this->actingAs($this->chofer->user, 'sanctum')->postJson("/api/v1/rutas/{$rutaId}/iniciar")->assertOk();
    expect($this->camion->fresh()->estado_operativo)->toBe('en_ruta');

    $ruta = Ruta::with('paradas')->findOrFail($rutaId);
    $this->actingAs($this->chofer->user, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/sincronizar-lote", ['recolecciones' => [[
            'punto_id' => $ruta->paradas[0]->punto_id,
            'sync_uuid' => Str::uuid()->toString(),
            'hora_real_recoleccion' => now()->toIso8601String(),
        ]]])
        ->assertOk();

    $this->actingAs($this->adminUser, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/cancelar", ['motivo' => 'Lluvia fuerte'])
        ->assertOk();

    $paradas = Ruta::findOrFail($rutaId)->paradas;
    expect($paradas[0]->estado_parada)->toBe('recolectado')
        ->and($paradas[1]->estado_parada)->toBe('pendiente')
        ->and($this->camion->fresh()->estado_operativo)->toBe('disponible');

    // Cancelada: ya no admite recolecciones ni finalizar.
    $this->actingAs($this->chofer->user, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/finalizar")
        ->assertUnprocessable();
});

test('no se puede cancelar una ruta completada ni una ya cancelada, ni un chofer puede cancelar', function () {
    $rutaId = generarRutaParaCancelar($this);

    $this->actingAs($this->chofer->user, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/cancelar", ['motivo' => 'Quiero irme'])
        ->assertForbidden();

    $this->actingAs($this->chofer->user, 'sanctum')->postJson("/api/v1/rutas/{$rutaId}/iniciar")->assertOk();
    $this->actingAs($this->chofer->user, 'sanctum')->postJson("/api/v1/rutas/{$rutaId}/finalizar")->assertOk();

    $this->actingAs($this->adminUser, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/cancelar", ['motivo' => 'Tarde'])
        ->assertStatus(409);

    expect(Ruta::findOrFail($rutaId)->estado)->toBe(Ruta::ESTADO_COMPLETADA);
});

test('cancelar no pisa el estado de un camión en mantenimiento', function () {
    $rutaId = generarRutaParaCancelar($this);
    $this->camion->update(['estado_operativo' => 'mantenimiento']);

    $this->actingAs($this->adminUser, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/cancelar", ['motivo' => 'Revisión'])
        ->assertOk();

    expect($this->camion->fresh()->estado_operativo)->toBe('mantenimiento');
});

test('una ruta cancelada aún recibe las recolecciones que el chofer hizo sin señal', function () {
    $rutaId = generarRutaParaCancelar($this);
    $this->actingAs($this->chofer->user, 'sanctum')->postJson("/api/v1/rutas/{$rutaId}/iniciar")->assertOk();
    $this->actingAs($this->adminUser, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/cancelar", ['motivo' => 'Cambio de plan'])
        ->assertOk();

    $puntoId = Ruta::with('paradas')->findOrFail($rutaId)->paradas[0]->punto_id;
    $this->actingAs($this->chofer->user, 'sanctum')
        ->postJson("/api/v1/rutas/{$rutaId}/sincronizar-lote", ['recolecciones' => [[
            'punto_id' => $puntoId,
            'sync_uuid' => Str::uuid()->toString(),
            'hora_real_recoleccion' => now()->subMinutes(5)->toIso8601String(),
        ]]])
        ->assertOk()
        ->assertJsonPath('data.estado', Ruta::ESTADO_CANCELADA)
        ->assertJsonPath('data.paradas.0.estado_parada', 'recolectado');
});
