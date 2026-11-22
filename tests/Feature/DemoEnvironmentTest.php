<?php

use App\Models\Administrador;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\Dispositivo;
use App\Models\LecturaPunto;
use App\Models\ProyeccionFlota;
use App\Models\Punto;
use App\Models\Ruta;
use App\Models\RutaPunto;
use App\Models\Sector;
use App\Models\TelemetriaGps;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\SectorSeeder;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

afterEach(function (): void {
    Carbon::setTestNow();
});

function configurarEntornoDemo(): void
{
    config([
        'app.demo_mode' => true,
        'app.admin.email' => 'admin.empresa@terranova.test',
        'app.admin.password' => 'ClaveEmpresaSegura#2026',
        'app.demo.admin_email' => 'admin@demo.ecoruta.cl',
        'app.demo.admin_password' => 'DemoAdmin#2026',
        'app.demo.chofer_password' => 'DemoChofer#2026',
    ]);
}

test('en modo demostracion queda una sola cuenta administradora, 2 choferes reales, 3 camiones demo y los 12 sectores reales sin direcciones duplicadas', function () {
    configurarEntornoDemo();

    // Ejecutar ambos seeders en demo no debe crear la cuenta de la empresa.
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(User::where('role', 'administrador')->count())->toBe(1)
        ->and(User::where('email', 'admin.empresa@terranova.test')->exists())->toBeFalse()
        ->and(Administrador::count())->toBe(1)
        ->and(Administrador::first()?->nombre_completo)->toBe('Administración EcoRuta');

    $nombresChoferes = Chofer::all()->map(static fn (Chofer $c): string => $c->nombre_cifrado)->sort()->values()->all();
    expect($nombresChoferes)->toBe(['Maximiliano González', 'Nelson Ponce']);

    expect(Camion::count())->toBe(3)
        ->and(Camion::whereNotNull('rendimiento_km_l')->whereNotNull('costo_arriendo_diario')->count())->toBe(3);

    $puntos = Punto::all();
    expect($puntos->count())->toBe(16);

    // Los 12 sectores reales existen y cada punto pertenece a uno de ellos.
    $nombresReales = collect(SectorSeeder::SECTORES)->pluck('nombre')->sort()->values()->all();
    expect(Sector::orderBy('nombre')->pluck('nombre')->all())->toBe($nombresReales)
        ->and($puntos->whereNull('sector_id')->count())->toBe(0)
        ->and($puntos->every(static fn (Punto $p): bool => in_array($p->sectorResuelto(), $nombresReales, true)))->toBeTrue();

    $direcciones = $puntos->map(static fn (Punto $p): string => mb_strtolower(trim($p->direccion)));
    expect($direcciones->unique()->count())->toBe($puntos->count());

    // Inicio de sesión con credenciales de demostración desde .env.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@demo.ecoruta.cl',
        'password' => 'DemoAdmin#2026',
        'device_name' => 'demo-admin',
    ])->assertOk()->assertJsonPath('role', 'administrador');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'nelson.ponce@demo.ecoruta.cl',
        'password' => 'DemoChofer#2026',
        'device_name' => 'demo-chofer',
    ])->assertOk()->assertJsonPath('role', 'chofer');
});

test('ecoruta:generar-historial se niega cuando APP_DEMO_MODE es false', function () {
    config(['app.demo_mode' => false]);

    $this->artisan('ecoruta:generar-historial', ['--meses' => 1])
        ->expectsOutputToContain('APP_DEMO_MODE=false')
        ->assertExitCode(1);
});

test('ecoruta:generar-historial llena los 11 KPI sin estados vacios, genera alertas y es reproducible e idempotente', function () {
    // El KPI-05 mide hasta "ahora" los episodios de contenedor lleno que siguen abiertos, y
    // el historial se ancla a la hora actual: con el reloj real, los segundos entre las dos
    // ejecuciones mueven los episodios cercanos a las 12 h. Se fija el reloj (determinista).
    Carbon::setTestNow(Carbon::parse('2027-03-15 11:10:00'));
    configurarEntornoDemo();
    $this->seed(DemoSeeder::class);

    $this->artisan('ecoruta:generar-historial', [
        '--meses' => 2,
        '--semilla' => 20261121,
    ])->assertSuccessful();

    $conteosPrimero = [
        'rutas' => Ruta::count(),
        'paradas' => RutaPunto::count(),
        'pings' => TelemetriaGps::count(),
        'lecturas' => LecturaPunto::count(),
        'proyecciones' => ProyeccionFlota::count(),
    ];
    expect($conteosPrimero['rutas'])->toBeGreaterThan(20)
        ->and($conteosPrimero['pings'])->toBeGreaterThan(100)
        ->and($conteosPrimero['lecturas'])->toBeGreaterThan(100)
        ->and($conteosPrimero['proyecciones'])->toBeGreaterThan(10);

    $adminUser = User::where('role', 'administrador')->firstOrFail();
    Sanctum::actingAs($adminUser);

    $dashPrimero = $this->getJson('/api/v1/kpis/dashboard')
        ->assertOk()
        ->json('data');

    expect($dashPrimero['kpis'])->toHaveCount(11);
    foreach ($dashPrimero['kpis'] as $kpi) {
        expect($kpi['sin_datos_suficientes'])
            ->toBeFalse("El indicador {$kpi['id']} ({$kpi['titulo']}) no debe quedar sin datos suficientes.");
    }

    $alertas = $this->getJson('/api/v1/kpis/alertas')
        ->assertOk()
        ->json('data');
    $tiposAlerta = collect($alertas)->pluck('tipo')->unique()->values()->all();
    expect($tiposAlerta)->toContain('desborde')
        ->toContain('parada_omitida')
        ->toContain('sensor_sin_reporte')
        ->toContain('bateria_baja');

    // Segunda ejecución con la misma semilla y el mismo reloj: idempotente (sin duplicar
    // filas ni cambiar valores KPI).
    $this->artisan('ecoruta:generar-historial', [
        '--meses' => 2,
        '--semilla' => 20261121,
    ])->assertSuccessful();

    expect([
        'rutas' => Ruta::count(),
        'paradas' => RutaPunto::count(),
        'pings' => TelemetriaGps::count(),
        'lecturas' => LecturaPunto::count(),
        'proyecciones' => ProyeccionFlota::count(),
    ])->toBe($conteosPrimero);

    $dashSegundo = $this->getJson('/api/v1/kpis/dashboard')
        ->assertOk()
        ->json('data');

    $valoresPrimero = collect($dashPrimero['kpis'])->pluck('valor', 'id')->all();
    $valoresSegundo = collect($dashSegundo['kpis'])->pluck('valor', 'id')->all();
    expect($valoresSegundo)->toBe($valoresPrimero);
});

test('ecoruta:generar-historial ancla rutas, lecturas y heartbeats de sensores a la fecha actual simulada', function () {
    Carbon::setTestNow(Carbon::parse('2027-03-15 11:00:00'));

    try {
        configurarEntornoDemo();
        $this->seed(DemoSeeder::class);

        $this->artisan('ecoruta:generar-historial', [
            '--meses' => 2,
            '--semilla' => 777,
        ])->assertSuccessful();

        $ultimaRuta = Ruta::query()->orderByDesc('fecha_programada')->firstOrFail();
        expect($ultimaRuta->fecha_programada->format('Y-m-d'))->toBe('2027-03-15');

        // Los sensores sanos reportaron hace < 8 h respecto a la fecha simulada.
        $sensoresRecientes = Punto::query()
            ->whereNotNull('sensor_codigo')
            ->where('ultimo_heartbeat_iot', '>=', Carbon::now()->subHours(8))
            ->count();
        expect($sensoresRecientes)->toBeGreaterThanOrEqual(10);

        $adminUser = User::where('role', 'administrador')->firstOrFail();
        Sanctum::actingAs($adminUser);

        $kpis = $this->getJson('/api/v1/kpis/dashboard')
            ->assertOk()
            ->json('data.kpis');

        expect($kpis)->toHaveCount(11);
        foreach ($kpis as $kpi) {
            expect($kpi['sin_datos_suficientes'])
                ->toBeFalse("En 2027-03-15, {$kpi['id']} debe tener datos suficientes.");
        }
    } finally {
        Carbon::setTestNow();
    }
});

test('los sensores demo quedan simulados aunque ya existieran como dispositivos sin simulacion y se ponen al dia al consultar', function () {
    configurarEntornoDemo();
    config(['iot.simulador.espera_seg' => 0]);
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoSeeder::class);

    // Estado que dejo la migracion de dispositivos: mismo codigo, sin token ni simulacion.
    $total = Dispositivo::count();
    expect($total)->toBeGreaterThan(5);
    Dispositivo::query()->update(['es_simulado' => false, 'simulacion' => null, 'token_hash' => null]);

    $this->seed(DemoSeeder::class);

    expect(Dispositivo::count())->toBe($total)
        ->and(Dispositivo::where('es_simulado', true)->count())->toBe($total);

    // Pasa un dia: el simple listado debe completar las lecturas, sin botones ni comandos.
    $this->travelTo(now()->addHours(25));
    $admin = User::where('role', 'administrador')->firstOrFail();
    Sanctum::actingAs($admin);

    $estados = collect($this->getJson('/api/v1/dispositivos')->assertOk()->json('data'))->pluck('estado_conexion');

    expect($estados->filter(fn ($e) => $e === 'sin_reportar')->count())->toBeLessThan($total)
        ->and($estados->filter(fn ($e) => $e === 'en_linea' || $e === 'bateria_baja')->count())->toBeGreaterThan($total / 2);
});
