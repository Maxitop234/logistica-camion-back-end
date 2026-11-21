<?php

use App\Models\Administrador;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\Punto;
use App\Models\Ruta;
use App\Models\RutaPunto;
use App\Models\User;
use Illuminate\Support\Str;

function crearEscenarioGps(string $estadoRuta = Ruta::ESTADO_EN_CURSO): array
{
    $adminUser = User::factory()->create(['role' => 'administrador']);
    $admin = Administrador::factory()->create(['user_id' => $adminUser->id]);

    $choferUser = User::factory()->create(['role' => 'chofer']);
    $chofer = Chofer::factory()->create([
        'user_id' => $choferUser->id,
        'disponible' => true,
        'avatar_config' => [
            'peinado' => 'ondas',
            'color_pelo' => 'castano',
            'tono_piel' => 'medio',
            'lentes' => true,
        ],
    ]);

    $camion = Camion::factory()->create([
        'patente' => 'GPS-101',
        'estado_operativo' => $estadoRuta === Ruta::ESTADO_EN_CURSO ? 'en_ruta' : 'disponible',
        'chofer_actual_id' => $chofer->id,
        'ultima_latitud' => null,
        'ultima_longitud' => null,
        'velocidad_actual_kmh' => 0,
        'ultima_actualizacion_gps' => null,
    ]);

    $punto = Punto::create([
        'nombre' => 'Condominio Norte',
        'direccion' => 'Av. Alemania 1200',
        'tipo_punto' => Punto::TIPO_CONDOMINIO,
        'latitud' => -38.7350,
        'longitud' => -72.6000,
        'porcentaje_llenado' => 85,
        'estado_llenado' => Punto::ESTADO_ALTO,
        'activo' => true,
    ]);

    $ruta = Ruta::create([
        'camion_id' => $camion->id,
        'chofer_id' => $chofer->id,
        'creado_por_admin_id' => $admin->id,
        'fecha_programada' => today()->format('Y-m-d'),
        'estado' => $estadoRuta,
        'total_puntos_evaluados' => 1,
        'puntos_incluidos_count' => 1,
        'puntos_excluidos_count' => 0,
        'distancia_estimada_km' => 3.50,
        'distancia_real_km' => 0,
        'duracion_estimada_min' => 15,
        'encoded_polyline' => '_p~iF~ps|U_ulLnnqC',
        'hora_inicio_real' => $estadoRuta === Ruta::ESTADO_PLANIFICADA ? null : now()->subMinutes(20),
        'hora_termino_real' => $estadoRuta === Ruta::ESTADO_COMPLETADA ? now()->subMinutes(5) : null,
    ]);

    RutaPunto::create([
        'ruta_id' => $ruta->id,
        'punto_id' => $punto->id,
        'orden_parada' => 1,
        'estado_llenado_inicial' => Punto::ESTADO_ALTO,
        'estado_parada' => RutaPunto::ESTADO_PENDIENTE,
    ]);

    return compact('adminUser', 'choferUser', 'chofer', 'camion', 'ruta');
}

it('registra lote de pings desordenado en orden cronológico, filtra ruido y mantiene idempotencia', function (): void {
    ['choferUser' => $choferUser, 'camion' => $camion, 'ruta' => $ruta] = crearEscenarioGps();

    $t1 = now()->subMinutes(5)->toIso8601String();
    $t2 = now()->subMinutes(4)->toIso8601String();
    $t3 = now()->subMinutes(3)->toIso8601String();
    $t4 = now()->subMinutes(2)->toIso8601String();
    $t5 = now()->subMinutes(1)->toIso8601String();

    $uuid1 = (string) Str::uuid();
    $uuid2 = (string) Str::uuid();
    $uuidRuidoPrecision = (string) Str::uuid();
    $uuidDetenidoDrift = (string) Str::uuid();
    $uuid3 = (string) Str::uuid();

    // Lote desordenado: t5 primero, t1 después.
    $response = $this->actingAs($choferUser, 'sanctum')
        ->postJson('/api/v1/gps/ping', [
            'pings' => [
                [
                    'ping_uuid' => $uuid3,
                    'ruta_id' => $ruta->id,
                    'latitud' => -38.7300,
                    'longitud' => -72.5900,
                    'velocidad_kmh' => 30.0,
                    'precision_m' => 10.0,
                    'capturado_en' => $t5,
                ],
                [
                    'ping_uuid' => $uuid1,
                    'ruta_id' => $ruta->id,
                    'latitud' => -38.7400,
                    'longitud' => -72.5900,
                    'velocidad_kmh' => 28.0,
                    'precision_m' => 8.0,
                    'capturado_en' => $t1,
                ],
                [
                    'ping_uuid' => $uuidRuidoPrecision,
                    'ruta_id' => $ruta->id,
                    'latitud' => -38.7100, // ~3.3 km de salto pero mala precisión (> 50 m)
                    'longitud' => -72.5900,
                    'velocidad_kmh' => 25.0,
                    'precision_m' => 85.0,
                    'capturado_en' => $t2,
                ],
                [
                    'ping_uuid' => $uuid2,
                    'ruta_id' => $ruta->id,
                    'latitud' => -38.7350, // ~556 m desde t1
                    'longitud' => -72.5900,
                    'velocidad_kmh' => 0.5,
                    'precision_m' => 12.0,
                    'capturado_en' => $t3,
                ],
                [
                    'ping_uuid' => $uuidDetenidoDrift,
                    'ruta_id' => $ruta->id,
                    'latitud' => -38.73492, // ~9 m de deriva estando detenido (< 15 m)
                    'longitud' => -72.5900,
                    'velocidad_kmh' => 0.4,
                    'precision_m' => 12.0,
                    'capturado_en' => $t4,
                ],
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.aceptados', 5)
        ->assertJsonPath('data.duplicados', 0)
        ->assertJsonPath('data.rechazados', 0);

    // La distancia real válida de -38.7400 -> -38.7350 -> -38.7300 es ~1.11 km (sin contar ruido ni deriva).
    $ruta->refresh();
    expect((float) $ruta->distancia_real_km)->toBeGreaterThan(1.05)->toBeLessThan(1.18);

    // Última posición del camión corresponde a t5 (el más reciente), no al último del array.
    $camion->refresh();
    expect((float) $camion->ultima_latitud)->toBe(-38.73)
        ->and((float) $camion->velocidad_actual_kmh)->toBe(30.0);

    // Enviar un ping más antiguo NO debe sobrescribir `camiones.ultima_*`.
    $this->actingAs($choferUser, 'sanctum')
        ->postJson('/api/v1/gps/ping', [
            'ping_uuid' => (string) Str::uuid(),
            'ruta_id' => $ruta->id,
            'latitud' => -38.7390,
            'longitud' => -72.5900,
            'velocidad_kmh' => 15.0,
            'precision_m' => 10.0,
            'capturado_en' => now()->subMinutes(10)->toIso8601String(),
        ])
        ->assertCreated();

    $camion->refresh();
    expect((float) $camion->ultima_latitud)->toBe(-38.73);

    // Reintento del mismo UUID es idempotente.
    $this->actingAs($choferUser, 'sanctum')
        ->postJson('/api/v1/gps/ping', [
            'ping_uuid' => $uuid1,
            'ruta_id' => $ruta->id,
            'latitud' => -38.7400,
            'longitud' => -72.5900,
            'velocidad_kmh' => 28.0,
            'precision_m' => 8.0,
            'capturado_en' => $t1,
        ])
        ->assertOk()
        ->assertJsonPath('data.aceptados', 0)
        ->assertJsonPath('data.duplicados', 1);
});

it('rechaza pings fuera de una ruta en curso (corte de privacidad Ley 21.719)', function (): void {
    ['choferUser' => $choferUser, 'ruta' => $ruta] = crearEscenarioGps(Ruta::ESTADO_PLANIFICADA);

    $this->actingAs($choferUser, 'sanctum')
        ->postJson('/api/v1/gps/ping', [
            'ping_uuid' => (string) Str::uuid(),
            'ruta_id' => $ruta->id,
            'latitud' => -38.7359,
            'longitud' => -72.5904,
            'velocidad_kmh' => 20,
            'capturado_en' => now()->toIso8601String(),
        ])
        ->assertConflict()
        ->assertJsonPath('data.rechazados', 1);
});

it('acepta pings rezagados tras finalizar la ruta solo si fueron capturados dentro de su ventana y recalcula distancia_real_km', function (): void {
    ['choferUser' => $choferUser, 'ruta' => $ruta] = crearEscenarioGps(Ruta::ESTADO_EN_CURSO);

    // Primer ping en vivo durante la ruta.
    $this->actingAs($choferUser, 'sanctum')
        ->postJson('/api/v1/gps/ping', [
            'ping_uuid' => (string) Str::uuid(),
            'ruta_id' => $ruta->id,
            'latitud' => -38.7400,
            'longitud' => -72.5900,
            'velocidad_kmh' => 25,
            'precision_m' => 9,
            'capturado_en' => now()->subMinutes(10)->toIso8601String(),
        ])
        ->assertCreated();

    // Finaliza la ruta antes de que llegue el tramo rezagado.
    $this->actingAs($choferUser, 'sanctum')
        ->postJson("/api/v1/rutas/{$ruta->id}/finalizar")
        ->assertOk()
        ->assertJsonPath('data.estado', Ruta::ESTADO_COMPLETADA)
        ->assertJsonPath('data.distancia_real_km', 0);

    // Llega un ping capturado ANTES de finalizar (dentro de [hora_inicio_real, hora_termino_real])
    // y otro capturado DESPUÉS de finalizar (fuera de ventana -> rechazado).
    $resp = $this->actingAs($choferUser, 'sanctum')
        ->postJson('/api/v1/gps/ping', [
            'pings' => [
                [
                    'ping_uuid' => (string) Str::uuid(),
                    'ruta_id' => $ruta->id,
                    'latitud' => -38.7300, // ~1.11 km dentro de ventana
                    'longitud' => -72.5900,
                    'velocidad_kmh' => 30,
                    'precision_m' => 10,
                    'capturado_en' => now()->subMinutes(2)->toIso8601String(),
                ],
                [
                    'ping_uuid' => (string) Str::uuid(),
                    'ruta_id' => $ruta->id,
                    'latitud' => -38.7200,
                    'longitud' => -72.5900,
                    'velocidad_kmh' => 35,
                    'precision_m' => 10,
                    'capturado_en' => now()->addSeconds(30)->toIso8601String(),
                ],
            ],
        ]);

    $resp->assertCreated()
        ->assertJsonPath('data.aceptados', 1)
        ->assertJsonPath('data.rechazados', 1);

    // La distancia real de la ruta finalizada se recalculó con el ping rezagado válido.
    $ruta->refresh();
    expect((float) $ruta->distancia_real_km)->toBeGreaterThan(1.05)->toBeLessThan(1.18);

    // Un ping suelto capturado después del término es rechazado con 409.
    $this->actingAs($choferUser, 'sanctum')
        ->postJson('/api/v1/gps/ping', [
            'ping_uuid' => (string) Str::uuid(),
            'ruta_id' => $ruta->id,
            'latitud' => -38.7150,
            'longitud' => -72.5900,
            'velocidad_kmh' => 25,
            'capturado_en' => now()->addMinutes(2)->toIso8601String(),
        ])
        ->assertConflict();
});

it('bloquea pings simulados cuando APP_DEMO_MODE es false y los permite cuando es true', function (): void {
    ['choferUser' => $choferUser, 'ruta' => $ruta] = crearEscenarioGps(Ruta::ESTADO_EN_CURSO);

    config(['app.demo_mode' => false]);

    $this->actingAs($choferUser, 'sanctum')
        ->postJson('/api/v1/gps/ping', [
            'ping_uuid' => (string) Str::uuid(),
            'ruta_id' => $ruta->id,
            'latitud' => -38.7359,
            'longitud' => -72.5904,
            'velocidad_kmh' => 24,
            'simulado' => true,
            'capturado_en' => now()->toIso8601String(),
        ])
        ->assertForbidden();

    config(['app.demo_mode' => true]);

    $this->actingAs($choferUser, 'sanctum')
        ->postJson('/api/v1/gps/ping', [
            'ping_uuid' => (string) Str::uuid(),
            'ruta_id' => $ruta->id,
            'latitud' => -38.7359,
            'longitud' => -72.5904,
            'velocidad_kmh' => 24,
            'simulado' => true,
            'capturado_en' => now()->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.aceptados', 1);
});

it('expone flota en vivo y traza de ruta solo al administrador con estado de señal y avatar', function (): void {
    ['adminUser' => $adminUser, 'choferUser' => $choferUser, 'camion' => $camion, 'ruta' => $ruta] = crearEscenarioGps();

    // Camión adicional con señal atrasada (> 30 s) y otro inactivo (excluido).
    Camion::factory()->create([
        'patente' => 'ATR-202',
        'estado_operativo' => 'disponible',
        'ultima_latitud' => -38.7500,
        'ultima_longitud' => -72.6000,
        'velocidad_actual_kmh' => 0,
        'ultima_actualizacion_gps' => now()->subMinutes(3),
    ]);
    Camion::factory()->create([
        'patente' => 'INA-999',
        'estado_operativo' => 'inactivo',
    ]);

    // Registrar 2 pings recientes en la ruta activa.
    $this->actingAs($choferUser, 'sanctum')
        ->postJson('/api/v1/gps/ping', [
            'pings' => [
                [
                    'ping_uuid' => (string) Str::uuid(),
                    'ruta_id' => $ruta->id,
                    'latitud' => -38.7400,
                    'longitud' => -72.5900,
                    'velocidad_kmh' => 20,
                    'precision_m' => 10,
                    'capturado_en' => now()->subSeconds(20)->toIso8601String(),
                ],
                [
                    'ping_uuid' => (string) Str::uuid(),
                    'ruta_id' => $ruta->id,
                    'latitud' => -38.7360,
                    'longitud' => -72.5900,
                    'velocidad_kmh' => 32,
                    'precision_m' => 10,
                    'capturado_en' => now()->subSeconds(5)->toIso8601String(),
                ],
            ],
        ])
        ->assertCreated();

    // Chofer no puede consultar flota ni traza.
    $this->actingAs($choferUser, 'sanctum')
        ->getJson('/api/v1/gps/flota')
        ->assertForbidden();
    $this->actingAs($choferUser, 'sanctum')
        ->getJson("/api/v1/rutas/{$ruta->id}/traza")
        ->assertForbidden();

    // Admin recibe flota con estado de señal y avatar del chofer.
    $flotaResp = $this->actingAs($adminUser, 'sanctum')
        ->getJson('/api/v1/gps/flota')
        ->assertOk()
        ->assertJsonPath('meta.total_count', 2)
        ->assertJsonPath('meta.en_ruta_count', 1);

    $datos = collect($flotaResp->json('data'));
    $enRuta = $datos->firstWhere('patente', 'GPS-101');
    $atrasado = $datos->firstWhere('patente', 'ATR-202');

    expect($enRuta['estado_senal'])->toBe('en_linea')
        ->and($enRuta['chofer']['avatar']['peinado'])->toBe('ondas')
        ->and($enRuta['ruta_activa']['id'])->toBe($ruta->id)
        ->and($enRuta['ruta_activa']['distancia_real_km'])->toBeGreaterThan(0.4)
        ->and($atrasado['estado_senal'])->toBe('atrasada')
        ->and($datos->firstWhere('patente', 'INA-999'))->toBeNull();

    // Admin consulta traza histórica real de la ruta.
    $this->actingAs($adminUser, 'sanctum')
        ->getJson("/api/v1/rutas/{$ruta->id}/traza")
        ->assertOk()
        ->assertJsonPath('data.ruta_id', $ruta->id)
        ->assertJsonPath('data.encoded_polyline', '_p~iF~ps|U_ulLnnqC')
        ->assertJsonCount(2, 'data.puntos');
});
