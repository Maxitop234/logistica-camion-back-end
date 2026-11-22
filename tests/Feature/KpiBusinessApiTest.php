<?php

use App\Models\Administrador;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\LecturaPunto;
use App\Models\ParametroOperativo;
use App\Models\ProyeccionFlota;
use App\Models\Punto;
use App\Models\Ruta;
use App\Models\RutaPunto;
use App\Models\Sector;
use App\Models\TelemetriaGps;
use App\Models\User;
use Database\Seeders\SectorSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->admin = User::factory()->create(['role' => 'administrador']);
    $this->adminPerfil = Administrador::factory()->create(['user_id' => $this->admin->id]);

    $this->chofer = Chofer::factory()->create(['nombre_cifrado' => 'Pedro Soto']);
    $this->choferUser = $this->chofer->user;
});

function kpiPorId(array $json, string $id): array
{
    return collect($json['data']['kpis'])->firstWhere('id', $id);
}

/**
 * Sector de prueba con el nombre dado (se crea una sola vez por prueba).
 */
function sectorKpi(string $nombre): int
{
    return Sector::firstOrCreate(
        ['nombre' => $nombre],
        ['dias_recoleccion' => [2, 4], 'periodicidad_semanal' => 2],
    )->id;
}

it('devuelve estado sin_datos_suficientes para los 11 KPI cuando no hay historial', function (): void {
    $resp = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/kpis/dashboard?fecha_inicio=2026-11-01&fecha_fin=2026-11-10&agrupacion=dia')
        ->assertOk()
        ->assertJsonCount(11, 'data.kpis')
        ->assertJsonPath('data.tiene_valores_referencia', true);

    foreach ($resp->json('data.kpis') as $kpi) {
        expect($kpi['sin_datos_suficientes'])->toBeTrue()
            ->and($kpi['valor'])->toBeNull()
            ->and($kpi['semaforo'])->toBe('sin_datos')
            ->and($kpi['mensaje_sin_datos'])->toBe('Aún no hay datos suficientes para este período');
    }

    // Verifica que el límite de velocidad de referencia es 50 km/h (urbano Chile).
    $limiteVel = collect($resp->json('data.parametros'))
        ->firstWhere('clave', ParametroOperativo::LIMITE_VELOCIDAD_KMH);
    expect((float) $limiteVel['valor'])->toBe(50.0)
        ->and($limiteVel['es_referencia'])->toBeTrue();
});

it('calcula exactamente KPI-01, KPI-02 y KPI-03 contando todos los días arrendados incluso sin salida', function (): void {
    // Parámetros explícitos para aritmética exacta.
    $this->actingAs($this->admin, 'sanctum')
        ->putJson('/api/v1/kpis/parametros', [
            'precio_combustible_litro' => 1000,
            'horas_jornada' => 8,
        ])
        ->assertOk();

    // Camión arrendado los 10 días del período (creado antes del 1 de nov), pero solo sale 2 días.
    $camion = Camion::factory()->create([
        'patente' => 'COST-01',
        'rendimiento_km_l' => 5.0,
        'costo_arriendo_diario' => 100000,
        'estado_operativo' => 'disponible',
        'created_at' => Carbon::parse('2026-10-15 08:00:00'),
    ]);

    $puntos = [];
    for ($i = 1; $i <= 5; $i++) {
        $puntos[] = Punto::create([
            'nombre' => "Punto Costo {$i}",
            'direccion' => "Calle {$i}",
            'tipo_punto' => Punto::TIPO_CALLE,
            'sector_id' => sectorKpi('Centro'),
            'latitud' => -38.7359,
            'longitud' => -72.5904,
            'porcentaje_llenado' => 80,
            'estado_llenado' => Punto::ESTADO_ALTO,
            'activo' => true,
        ]);
    }

    // 2 salidas dentro de un período de 10 días: 25 km y 4 h cada una, 5 puntos vaciados y 5 excluidos cada una.
    foreach (['2026-11-02', '2026-11-05'] as $fecha) {
        $ruta = Ruta::create([
            'camion_id' => $camion->id,
            'chofer_id' => $this->chofer->id,
            'creado_por_admin_id' => $this->adminPerfil->id,
            'fecha_programada' => $fecha,
            'estado' => Ruta::ESTADO_COMPLETADA,
            'total_puntos_evaluados' => 10,
            'puntos_incluidos_count' => 5,
            'puntos_excluidos_count' => 5,
            'distancia_estimada_km' => 25.0,
            'distancia_real_km' => 25.0,
            'duracion_estimada_min' => 240,
            'hora_inicio_real' => Carbon::parse("{$fecha} 08:00:00"),
            'hora_termino_real' => Carbon::parse("{$fecha} 12:00:00"),
        ]);
        foreach ($puntos as $idx => $p) {
            RutaPunto::create([
                'ruta_id' => $ruta->id,
                'punto_id' => $p->id,
                'orden_parada' => $idx + 1,
                'estado_llenado_inicial' => Punto::ESTADO_ALTO,
                'porcentaje_llenado_al_vaciar' => 80,
                'estado_parada' => RutaPunto::ESTADO_RECOLECTADO,
                'hora_real_recoleccion' => Carbon::parse("{$fecha} 09:00:00"),
            ]);
        }
    }

    $resp = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/kpis/dashboard?fecha_inicio=2026-11-01&fecha_fin=2026-11-10&agrupacion=dia')
        ->assertOk();

    // KPI-01: Combustible = (50 km * $1000 / 5) = $10.000; Arriendo = $100.000 * 10 días = $1.000.000.
    // Total = $1.010.000 / 10 puntos vaciados = $101.000 / punto.
    $kpi01 = kpiPorId($resp->json(), 'KPI-01');
    expect((float) $kpi01['valor'])->toBe(101000.0)
        ->and($kpi01['extras']['dias_arrendados_totales'])->toBe(10)
        ->and((float) $kpi01['extras']['costo_arriendo'])->toBe(1000000.0);

    // KPI-02: 10 excluidos, km/parada = 50/10 = 5 km -> 50 km ahorrados; 8h/10*10 = 8 h ahorradas;
    // costo/km = $1.010.000 / 50 = $20.200 -> ahorro = $1.010.000.
    $kpi02 = kpiPorId($resp->json(), 'KPI-02');
    expect((float) $kpi02['extras']['km_ahorrados'])->toBe(50.0)
        ->and((float) $kpi02['extras']['horas_ahorradas'])->toBe(8.0)
        ->and((float) $kpi02['valor'])->toBe(1010000.0);

    // KPI-03: 8 h en ruta / (10 días arrendados * 8 h jornada = 80 h) = 10.0 % (8 días pagados sin salida).
    $kpi03 = kpiPorId($resp->json(), 'KPI-03');
    expect((float) $kpi03['valor'])->toBe(10.0)
        ->and($kpi03['extras']['dias_arrendados_totales'])->toBe(10)
        ->and($kpi03['extras']['dias_sin_salida_totales'])->toBe(8);
});

it('captura porcentaje_llenado_al_vaciar y calcula exactamente KPI-04', function (): void {
    $camion = Camion::factory()->create(['estado_operativo' => 'en_ruta']);
    $p1 = Punto::create([
        'nombre' => 'P1 Util', 'direccion' => 'Dir 1', 'tipo_punto' => Punto::TIPO_CALLE,
        'sector_id' => sectorKpi('Centro'), 'latitud' => -38.735, 'longitud' => -72.59,
        'porcentaje_llenado' => 82, 'estado_llenado' => Punto::ESTADO_ALTO, 'activo' => true,
    ]);
    $p2 = Punto::create([
        'nombre' => 'P2 Prematuro', 'direccion' => 'Dir 2', 'tipo_punto' => Punto::TIPO_CALLE,
        'sector_id' => sectorKpi('Poniente'), 'latitud' => -38.735, 'longitud' => -72.61,
        'porcentaje_llenado' => 40, 'estado_llenado' => Punto::ESTADO_BAJO, 'activo' => true,
    ]);

    $ruta = Ruta::create([
        'camion_id' => $camion->id, 'chofer_id' => $this->chofer->id,
        'creado_por_admin_id' => $this->adminPerfil->id,
        'fecha_programada' => '2026-11-10', 'estado' => Ruta::ESTADO_EN_CURSO,
        'total_puntos_evaluados' => 2, 'puntos_incluidos_count' => 2, 'puntos_excluidos_count' => 0,
        'distancia_estimada_km' => 10.0, 'distancia_real_km' => 10.0, 'duracion_estimada_min' => 30,
        'hora_inicio_real' => Carbon::parse('2026-11-10 08:00:00'),
    ]);

    $rp1 = RutaPunto::create([
        'ruta_id' => $ruta->id, 'punto_id' => $p1->id, 'orden_parada' => 1,
        'estado_llenado_inicial' => Punto::ESTADO_ALTO, 'estado_parada' => RutaPunto::ESTADO_PENDIENTE,
        'distancia_tramo_km' => 3.5,
    ]);
    $rp2 = RutaPunto::create([
        'ruta_id' => $ruta->id, 'punto_id' => $p2->id, 'orden_parada' => 2,
        'estado_llenado_inicial' => Punto::ESTADO_BAJO, 'estado_parada' => RutaPunto::ESTADO_PENDIENTE,
        'distancia_tramo_km' => 6.5,
    ]);

    // El chofer marca ambas paradas: debe guardar 82 y 40 antes de resetear los puntos a 0.
    $this->actingAs($this->choferUser, 'sanctum')
        ->postJson("/api/v1/rutas/{$ruta->id}/sincronizar-lote", [
            'recolecciones' => [
                [
                    'sync_uuid' => (string) Str::uuid(), 'punto_id' => $p1->id,
                    'hora_real_recoleccion' => '2026-11-10T08:15:00Z',
                ],
                [
                    'sync_uuid' => (string) Str::uuid(), 'punto_id' => $p2->id,
                    'hora_real_recoleccion' => '2026-11-10T08:30:00Z',
                ],
            ],
        ])
        ->assertOk();

    expect($rp1->fresh()->porcentaje_llenado_al_vaciar)->toBe(82)
        ->and($rp2->fresh()->porcentaje_llenado_al_vaciar)->toBe(40)
        ->and($p1->fresh()->porcentaje_llenado)->toBe(0);

    // KPI-04 con umbral 70 %: 1 útil de 2 = 50.0 %, km prematuras = 6.5 km.
    $resp = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/kpis/dashboard?fecha_inicio=2026-11-01&fecha_fin=2026-11-15')
        ->assertOk();

    $kpi04 = kpiPorId($resp->json(), 'KPI-04');
    expect((float) $kpi04['valor'])->toBe(50.0)
        ->and((float) $kpi04['extras']['km_visitas_prematuras'])->toBe(6.5);
});

it('calcula exactamente KPI-05 (riesgo desborde + crónicos) y KPI-06 (dimensionamiento)', function (): void {
    $cronico = Punto::create([
        'nombre' => 'Feria Pinto', 'direccion' => 'Barros Arana 1150', 'tipo_punto' => Punto::TIPO_CALLE,
        'sector_id' => sectorKpi('Centro'), 'latitud' => -38.74, 'longitud' => -72.585,
        'porcentaje_llenado' => 95, 'estado_llenado' => Punto::ESTADO_LLENO, 'activo' => true,
    ]);
    $normal = Punto::create([
        'nombre' => 'Plaza Sur', 'direccion' => 'Maquehue 1400', 'tipo_punto' => Punto::TIPO_CONDOMINIO,
        'sector_id' => sectorKpi('Padre Las Casas'), 'latitud' => -38.77, 'longitud' => -72.595,
        'porcentaje_llenado' => 25, 'estado_llenado' => Punto::ESTADO_BAJO, 'activo' => true,
    ]);
    $adecuado = Punto::create([
        'nombre' => 'Parque Norte', 'direccion' => 'Ortega 2200', 'tipo_punto' => Punto::TIPO_CONDOMINIO,
        'sector_id' => sectorKpi('Norte'), 'latitud' => -38.719, 'longitud' => -72.581,
        'porcentaje_llenado' => 75, 'estado_llenado' => Punto::ESTADO_ALTO, 'activo' => true,
    ]);

    // 3 episodios > 12 h (15 h cada uno) en Feria Pinto + llenado rápido (> 100 %/día -> subdimensionado).
    foreach ([2, 5, 8] as $dia) {
        LecturaPunto::create([
            'punto_id' => $cronico->id, 'origen' => Punto::ORIGEN_SENSOR_IOT,
            'porcentaje_llenado' => 20, 'estado_llenado' => Punto::ESTADO_BAJO,
            'medido_en' => Carbon::parse("2026-11-0{$dia} 00:00:00"),
        ]);
        LecturaPunto::create([
            'punto_id' => $cronico->id, 'origen' => Punto::ORIGEN_SENSOR_IOT,
            'porcentaje_llenado' => 95, 'estado_llenado' => Punto::ESTADO_LLENO,
            'medido_en' => Carbon::parse("2026-11-0{$dia} 06:00:00"), // +75% en 6h = 300%/día
        ]);
        LecturaPunto::create([
            'punto_id' => $cronico->id, 'origen' => Punto::ORIGEN_MANUAL,
            'porcentaje_llenado' => 0, 'estado_llenado' => Punto::ESTADO_VACIO,
            'medido_en' => Carbon::parse("2026-11-0{$dia} 21:00:00"), // 15 h lleno (> 12 h)
        ]);
    }

    // 1 episodio dentro de límite (6 h) + sobredimensionado en Plaza Sur (lento < 10%/día y máx < 40% para otro punto).
    LecturaPunto::create([
        'punto_id' => $normal->id, 'origen' => Punto::ORIGEN_SENSOR_IOT,
        'porcentaje_llenado' => 10, 'estado_llenado' => Punto::ESTADO_VACIO,
        'medido_en' => Carbon::parse('2026-11-02 08:00:00'),
    ]);
    LecturaPunto::create([
        'punto_id' => $normal->id, 'origen' => Punto::ORIGEN_SENSOR_IOT,
        'porcentaje_llenado' => 22, 'estado_llenado' => Punto::ESTADO_BAJO,
        'medido_en' => Carbon::parse('2026-11-05 08:00:00'), // +12% en 3 días = 4%/día, max 22% -> sobredimensionado
    ]);

    // Parque Norte: 1 episodio de 6 h (<= 12 h) y velocidad adecuada (40%/día).
    LecturaPunto::create([
        'punto_id' => $adecuado->id, 'origen' => Punto::ORIGEN_SENSOR_IOT,
        'porcentaje_llenado' => 50, 'estado_llenado' => Punto::ESTADO_MEDIO,
        'medido_en' => Carbon::parse('2026-11-03 08:00:00'),
    ]);
    LecturaPunto::create([
        'punto_id' => $adecuado->id, 'origen' => Punto::ORIGEN_SENSOR_IOT,
        'porcentaje_llenado' => 90, 'estado_llenado' => Punto::ESTADO_LLENO,
        'medido_en' => Carbon::parse('2026-11-04 08:00:00'), // +40%/día -> adecuado
    ]);
    LecturaPunto::create([
        'punto_id' => $adecuado->id, 'origen' => Punto::ORIGEN_MANUAL,
        'porcentaje_llenado' => 0, 'estado_llenado' => Punto::ESTADO_VACIO,
        'medido_en' => Carbon::parse('2026-11-04 14:00:00'), // 6 h lleno (<= 12 h)
    ]);

    $resp = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/kpis/dashboard?fecha_inicio=2026-11-01&fecha_fin=2026-11-12')
        ->assertOk();

    // KPI-05: 4 episodios totales (3 de 15 h + 1 de 6 h) => 1/4 = 25.0 %, 1 punto crónico (Feria Pinto).
    $kpi05 = kpiPorId($resp->json(), 'KPI-05');
    expect((float) $kpi05['valor'])->toBe(25.0)
        ->and($kpi05['extras']['puntos_cronicos'])->toHaveCount(1)
        ->and($kpi05['extras']['puntos_cronicos'][0]['nombre'])->toBe('Feria Pinto');

    // KPI-06: 3 evaluados (1 subdimensionado, 1 sobredimensionado, 1 adecuado) => 33.3 %.
    $kpi06 = kpiPorId($resp->json(), 'KPI-06');
    expect((float) $kpi06['valor'])->toBe(33.3)
        ->and($kpi06['extras']['subdimensionados'])->toHaveCount(1)
        ->and($kpi06['extras']['sobredimensionados'])->toHaveCount(1);
});

it('calcula exactamente KPI-07 (planificación) y KPI-08 (confiabilidad sensores)', function (): void {
    $camion = Camion::factory()->create();

    // 4 puntos activos: 2 IoT confiables (< 8 h y batería >= 3.40 V), 1 IoT atrasado (10 h), 1 solo manual.
    $pOk1 = Punto::create([
        'nombre' => 'IoT OK 1', 'direccion' => 'D1', 'tipo_punto' => Punto::TIPO_CALLE,
        'sector_id' => sectorKpi('Centro'), 'latitud' => -38.735, 'longitud' => -72.59,
        'porcentaje_llenado' => 80, 'estado_llenado' => Punto::ESTADO_ALTO, 'activo' => true,
        'origen_actualizacion' => Punto::ORIGEN_SENSOR_IOT, 'sensor_codigo' => 'ESP-01',
        'bateria_voltaje' => 3.90, 'ultimo_heartbeat_iot' => now()->subHours(2),
    ]);
    Punto::create([
        'nombre' => 'IoT OK 2', 'direccion' => 'D2', 'tipo_punto' => Punto::TIPO_CALLE,
        'sector_id' => sectorKpi('Centro'), 'latitud' => -38.736, 'longitud' => -72.591,
        'porcentaje_llenado' => 50, 'estado_llenado' => Punto::ESTADO_MEDIO, 'activo' => true,
        'origen_actualizacion' => Punto::ORIGEN_SENSOR_IOT, 'sensor_codigo' => 'ESP-02',
        'bateria_voltaje' => 3.70, 'ultimo_heartbeat_iot' => now()->subHours(5),
    ]);
    Punto::create([
        'nombre' => 'IoT Atrasado', 'direccion' => 'D3', 'tipo_punto' => Punto::TIPO_CALLE,
        'sector_id' => sectorKpi('Poniente'), 'latitud' => -38.735, 'longitud' => -72.61,
        'porcentaje_llenado' => 50, 'estado_llenado' => Punto::ESTADO_MEDIO, 'activo' => true,
        'origen_actualizacion' => Punto::ORIGEN_SENSOR_IOT, 'sensor_codigo' => 'ESP-03',
        'bateria_voltaje' => 3.20, 'ultimo_heartbeat_iot' => now()->subHours(10),
    ]);
    Punto::create([
        'nombre' => 'Solo Manual', 'direccion' => 'D4', 'tipo_punto' => Punto::TIPO_CALLE,
        'sector_id' => sectorKpi('Oriente'), 'latitud' => -38.735, 'longitud' => -72.57,
        'porcentaje_llenado' => 40, 'estado_llenado' => Punto::ESTADO_BAJO, 'activo' => true,
        'origen_actualizacion' => Punto::ORIGEN_MANUAL,
    ]);

    // Ruta para KPI-07: 100% completadas (40%), 5 min desvío ETA => 80% puntualidad (30%),
    // 10% desvío km (11 vs 10) => 90% adherencia (30%) => 40 + 24 + 27 = 91.0 %.
    $ruta = Ruta::create([
        'camion_id' => $camion->id, 'chofer_id' => $this->chofer->id,
        'creado_por_admin_id' => $this->adminPerfil->id,
        'fecha_programada' => '2026-11-10', 'estado' => Ruta::ESTADO_COMPLETADA,
        'total_puntos_evaluados' => 1, 'puntos_incluidos_count' => 1, 'puntos_excluidos_count' => 0,
        'distancia_estimada_km' => 10.0, 'distancia_real_km' => 11.0, 'duracion_estimada_min' => 30,
        'hora_inicio_real' => Carbon::parse('2026-11-10 08:00:00'),
        'hora_termino_real' => Carbon::parse('2026-11-10 08:30:00'),
    ]);
    RutaPunto::create([
        'ruta_id' => $ruta->id, 'punto_id' => $pOk1->id, 'orden_parada' => 1,
        'estado_llenado_inicial' => Punto::ESTADO_ALTO, 'estado_parada' => RutaPunto::ESTADO_RECOLECTADO,
        'hora_estimada_llegada' => Carbon::parse('2026-11-10 08:15:00'),
        'hora_real_recoleccion' => Carbon::parse('2026-11-10 08:20:00'),
    ]);

    $resp = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/kpis/dashboard?fecha_inicio=2026-11-01&fecha_fin=2026-11-15')
        ->assertOk();

    $kpi07 = kpiPorId($resp->json(), 'KPI-07');
    expect((float) $kpi07['valor'])->toBe(91.0);

    $kpi08 = kpiPorId($resp->json(), 'KPI-08');
    expect((float) $kpi08['valor'])->toBe(50.0);

    // Alertas reales detectan sensor sin reporte + batería baja.
    $alertas = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/kpis/alertas?fecha_inicio=2026-11-01&fecha_fin=2026-11-15')
        ->assertOk()
        ->json('data');

    expect(collect($alertas)->pluck('tipo')->all())
        ->toContain('sensor_sin_reporte', 'bateria_baja');
});

it('calcula y autoevalúa KPI-09 bajo demanda sin cron', function (): void {
    $camion = Camion::factory()->create();
    $ayer = today()->subDay();

    // 2 puntos activos que ayer alcanzaron >= 70% y hoy suben +20%/día (ambos listos mañana).
    $p1 = Punto::create([
        'nombre' => 'Proy 1', 'direccion' => 'D1', 'tipo_punto' => Punto::TIPO_CALLE,
        'sector_id' => sectorKpi('Centro'), 'latitud' => -38.735, 'longitud' => -72.59,
        'porcentaje_llenado' => 60, 'estado_llenado' => Punto::ESTADO_MEDIO, 'activo' => true,
    ]);
    $p2 = Punto::create([
        'nombre' => 'Proy 2', 'direccion' => 'D2', 'tipo_punto' => Punto::TIPO_CALLE,
        'sector_id' => sectorKpi('Centro'), 'latitud' => -38.736, 'longitud' => -72.591,
        'porcentaje_llenado' => 75, 'estado_llenado' => Punto::ESTADO_ALTO, 'activo' => true,
    ]);

    foreach ([$p1, $p2] as $p) {
        LecturaPunto::create([
            'punto_id' => $p->id, 'origen' => Punto::ORIGEN_SENSOR_IOT,
            'porcentaje_llenado' => 50, 'estado_llenado' => Punto::ESTADO_MEDIO,
            'medido_en' => $ayer->copy()->setTime(8, 0),
        ]);
        LecturaPunto::create([
            'punto_id' => $p->id, 'origen' => Punto::ORIGEN_SENSOR_IOT,
            'porcentaje_llenado' => 75, 'estado_llenado' => Punto::ESTADO_ALTO,
            'medido_en' => $ayer->copy()->setTime(20, 0), // +50%/día -> mañana >= 70%
        ]);
    }

    // Historial de 1 ruta completada con 1 parada => capacidad histórica = 1 punto/camión => 2 puntos necesitan 2 camiones.
    $ruta = Ruta::create([
        'camion_id' => $camion->id, 'chofer_id' => $this->chofer->id,
        'creado_por_admin_id' => $this->adminPerfil->id,
        'fecha_programada' => $ayer->format('Y-m-d'), 'estado' => Ruta::ESTADO_COMPLETADA,
        'total_puntos_evaluados' => 1, 'puntos_incluidos_count' => 1, 'puntos_excluidos_count' => 0,
        'distancia_estimada_km' => 5.0, 'distancia_real_km' => 5.0, 'duracion_estimada_min' => 30,
    ]);
    RutaPunto::create([
        'ruta_id' => $ruta->id, 'punto_id' => $p1->id, 'orden_parada' => 1,
        'estado_llenado_inicial' => Punto::ESTADO_ALTO, 'estado_parada' => RutaPunto::ESTADO_RECOLECTADO,
    ]);

    // Proyección de ayer pendiente de evaluación (2 proyectados vs 2 reales => 100 % acierto).
    $proyAyer = ProyeccionFlota::create([
        'fecha_objetivo' => $ayer->format('Y-m-d'),
        'puntos_proyectados' => 2,
        'camiones_proyectados' => 2,
        'calculada_en' => $ayer->copy()->subDay(),
    ]);

    $resp = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/kpis/dashboard')
        ->assertOk();

    $kpi09 = kpiPorId($resp->json(), 'KPI-09');
    expect($kpi09['es_proyeccion'])->toBeTrue()
        ->and((int) $kpi09['extras']['puntos_proyectados'])->toBe(2)
        ->and((int) $kpi09['extras']['camiones_proyectados'])->toBe(2)
        ->and((float) $kpi09['extras']['acierto_proyeccion_pct'])->toBe(100.0);

    // Se evaluó ayer y se guardó la proyección de mañana bajo demanda.
    expect($proyAyer->fresh()->puntos_reales_listos)->toBe(2)
        ->and((float) $proyAyer->fresh()->porcentaje_acierto)->toBe(100.0)
        ->and(ProyeccionFlota::whereDate('fecha_objetivo', today()->addDay()->format('Y-m-d'))->exists())->toBeTrue();
});

it('calcula exactamente KPI-10 (ranking ajustado por mediana de sector) y KPI-11 (costo conducción con límite 50 km/h)', function (): void {
    $this->actingAs($this->admin, 'sanctum')
        ->putJson('/api/v1/kpis/parametros', ['precio_combustible_litro' => 1000])
        ->assertOk();

    $chofer2 = Chofer::factory()->create(['nombre_cifrado' => 'Camila Rojas']);
    $camion1 = Camion::factory()->create(['patente' => 'DRV-01', 'rendimiento_km_l' => 5.0]);
    $camion2 = Camion::factory()->create(['patente' => 'DRV-02', 'rendimiento_km_l' => 5.0]);
    $punto = Punto::create([
        'nombre' => 'Centro 1', 'direccion' => 'Prat 100', 'tipo_punto' => Punto::TIPO_CALLE,
        'sector_id' => sectorKpi('Centro'), 'latitud' => -38.735, 'longitud' => -72.59,
        'porcentaje_llenado' => 80, 'estado_llenado' => Punto::ESTADO_ALTO, 'activo' => true,
    ]);

    // Chofer 1 (Pedro): 100% paradas, 0 desvío ETA, 15 km reales vs 10 km est (5 km extra), 1/2 pings > 50 km/h.
    $r1 = Ruta::create([
        'camion_id' => $camion1->id, 'chofer_id' => $this->chofer->id,
        'creado_por_admin_id' => $this->adminPerfil->id,
        'fecha_programada' => '2026-11-10', 'estado' => Ruta::ESTADO_COMPLETADA,
        'total_puntos_evaluados' => 2, 'puntos_incluidos_count' => 2, 'puntos_excluidos_count' => 0,
        'distancia_estimada_km' => 10.0, 'distancia_real_km' => 15.0, 'duracion_estimada_min' => 60,
        'hora_inicio_real' => Carbon::parse('2026-11-10 08:00:00'),
        'hora_termino_real' => Carbon::parse('2026-11-10 09:00:00'),
    ]);
    foreach ([1, 2] as $orden) {
        RutaPunto::create([
            'ruta_id' => $r1->id, 'punto_id' => $punto->id, 'orden_parada' => $orden,
            'estado_llenado_inicial' => Punto::ESTADO_ALTO, 'estado_parada' => RutaPunto::ESTADO_RECOLECTADO,
            'hora_estimada_llegada' => Carbon::parse('2026-11-10 08:30:00'),
            'hora_real_recoleccion' => Carbon::parse('2026-11-10 08:30:00'),
        ]);
    }
    TelemetriaGps::create([
        'ruta_id' => $r1->id, 'camion_id' => $camion1->id, 'chofer_id' => $this->chofer->id,
        'ping_uuid' => (string) Str::uuid(), 'latitud' => -38.735, 'longitud' => -72.59,
        'velocidad_kmh' => 40.0, 'capturado_en' => Carbon::parse('2026-11-10 08:10:00'),
    ]);
    TelemetriaGps::create([
        'ruta_id' => $r1->id, 'camion_id' => $camion1->id, 'chofer_id' => $this->chofer->id,
        'ping_uuid' => (string) Str::uuid(), 'latitud' => -38.736, 'longitud' => -72.59,
        'velocidad_kmh' => 65.0, 'capturado_en' => Carbon::parse('2026-11-10 08:20:00'), // > 50 km/h
    ]);

    // Chofer 2 (Camila): 100% paradas, 0 desvío ETA, 10 km reales vs 10 km est (0 km extra), 0 excesos, 30 min (mayor productividad).
    $r2 = Ruta::create([
        'camion_id' => $camion2->id, 'chofer_id' => $chofer2->id,
        'creado_por_admin_id' => $this->adminPerfil->id,
        'fecha_programada' => '2026-11-10', 'estado' => Ruta::ESTADO_COMPLETADA,
        'total_puntos_evaluados' => 2, 'puntos_incluidos_count' => 2, 'puntos_excluidos_count' => 0,
        'distancia_estimada_km' => 10.0, 'distancia_real_km' => 10.0, 'duracion_estimada_min' => 30,
        'hora_inicio_real' => Carbon::parse('2026-11-10 10:00:00'),
        'hora_termino_real' => Carbon::parse('2026-11-10 10:30:00'),
    ]);
    foreach ([1, 2] as $orden) {
        RutaPunto::create([
            'ruta_id' => $r2->id, 'punto_id' => $punto->id, 'orden_parada' => $orden,
            'estado_llenado_inicial' => Punto::ESTADO_ALTO, 'estado_parada' => RutaPunto::ESTADO_RECOLECTADO,
            'hora_estimada_llegada' => Carbon::parse('2026-11-10 10:15:00'),
            'hora_real_recoleccion' => Carbon::parse('2026-11-10 10:15:00'),
        ]);
    }
    TelemetriaGps::create([
        'ruta_id' => $r2->id, 'camion_id' => $camion2->id, 'chofer_id' => $chofer2->id,
        'ping_uuid' => (string) Str::uuid(), 'latitud' => -38.735, 'longitud' => -72.59,
        'velocidad_kmh' => 45.0, 'capturado_en' => Carbon::parse('2026-11-10 10:10:00'),
    ]);

    $resp = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/kpis/dashboard?fecha_inicio=2026-11-01&fecha_fin=2026-11-15')
        ->assertOk();

    // KPI-10: Camila lidera el ranking sobre Pedro en el mismo sector Centro con desglose de los 4 aportes.
    $kpi10 = kpiPorId($resp->json(), 'KPI-10');
    $ranking = $kpi10['extras']['ranking'];
    expect($ranking)->toHaveCount(2)
        ->and($ranking[0]['nombre'])->toBe('Camila Rojas')
        ->and($ranking[0]['puntaje'])->toBeGreaterThan($ranking[1]['puntaje'])
        ->and($ranking[0])->toHaveKeys(['aporte_completitud', 'aporte_puntualidad', 'aporte_km', 'aporte_productividad']);

    // KPI-11: Pedro tiene 5 km extra => 5/5 = 1.0 L; excesos 1/2 en 15 km => (15 * 0.5 / 5) * 0.15 = 0.225 L;
    // total redondeado = 1.23 L * $1.000 = $1.230.
    $kpi11 = kpiPorId($resp->json(), 'KPI-11');
    expect((float) $kpi11['extras']['km_extra'])->toBe(5.0)
        ->and((int) $kpi11['extras']['pings_exceso'])->toBe(1)
        ->and((float) $kpi11['extras']['litros_extra'])->toBe(1.23)
        ->and((float) $kpi11['valor'])->toBe(1230.0);
});

it('valida que el sector exista y exporta reportes válidos en PDF (DomPDF) y CSV', function (): void {
    $this->seed(SectorSeeder::class);

    // Un sector que no está registrado debe rechazarse con 422.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/puntos', [
            'nombre' => 'Punto Sector Inválido',
            'direccion' => 'Calle Falsa 123',
            'tipo_punto' => Punto::TIPO_CALLE,
            'sector' => 'centro ',
            'latitud' => -38.7359,
            'longitud' => -72.5904,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sector']);

    // Un sector registrado (sin distinguir mayúsculas) se guarda correctamente.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/puntos', [
            'nombre' => 'Punto Ñielol',
            'direccion' => 'Av. Prat 123, Temuco',
            'tipo_punto' => Punto::TIPO_CALLE,
            'sector' => 'poniente',
            'latitud' => -38.7200,
            'longitud' => -72.5900,
        ])
        ->assertCreated()
        ->assertJsonPath('data.sector', 'Poniente')
        ->assertJsonPath('data.sector_resuelto', 'Poniente');

    // Exportación CSV válida UTF-8 BOM.
    $csvResp = $this->actingAs($this->admin, 'sanctum')
        ->get('/api/v1/kpis/exportar?formato=csv&fecha_inicio=2026-11-01&fecha_fin=2026-11-15')
        ->assertOk();
    expect($csvResp->headers->get('content-type'))->toContain('text/csv')
        ->and($csvResp->getContent())->toContain('KPI-01', 'KPI-11');

    // Exportación PDF válida generada con barryvdh/laravel-dompdf.
    $pdfResp = $this->actingAs($this->admin, 'sanctum')
        ->get('/api/v1/kpis/exportar?formato=pdf&fecha_inicio=2026-11-01&fecha_fin=2026-11-15')
        ->assertOk();
    expect($pdfResp->headers->get('content-type'))->toContain('application/pdf')
        ->and(str_starts_with((string) $pdfResp->getContent(), '%PDF-'))->toBeTrue();
});
