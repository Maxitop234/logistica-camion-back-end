<?php

use App\Models\Administrador;
use App\Models\Chofer;
use App\Models\Punto;
use App\Models\Sector;
use App\Models\User;
use Database\Seeders\SectorSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'administrador']);
    Administrador::factory()->create(['user_id' => $this->admin->id]);
});

test('SectorSeeder carga los 12 sectores reales con sus dias y es repetible', function () {
    $this->seed(SectorSeeder::class);
    $this->seed(SectorSeeder::class);

    expect(Sector::count())->toBe(12);

    $labranza = Sector::where('nombre', 'Labranza')->firstOrFail();
    expect($labranza->diasNormalizados())->toBe([4])
        ->and($labranza->puntos_esperados)->toBe(40);

    // La planilla declara 1 vez por semana pero marca jueves y viernes: mandan los días.
    $sanMartin = Sector::where('nombre', 'S.Martin, Av.Alemania, Thiers, P.Dreves')->firstOrFail();
    expect($sanMartin->diasNormalizados())->toBe([4, 5])
        ->and($sanMartin->diasCoincidenConPeriodicidad())->toBeFalse();
});

test('el administrador lista los sectores con sus puntos cargados frente a los esperados', function () {
    $this->seed(SectorSeeder::class);
    $poniente = Sector::where('nombre', 'Poniente')->firstOrFail();
    Punto::factory()->count(2)->create(['sector_id' => $poniente->id]);
    Punto::factory()->create(['sector_id' => $poniente->id, 'activo' => false]);

    $respuesta = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/sectores')
        ->assertOk()
        ->assertJsonCount(12, 'data');

    $fila = collect($respuesta->json('data'))->firstWhere('nombre', 'Poniente');
    expect($fila['dias_recoleccion'])->toBe([1, 3, 5])
        ->and($fila['dias_recoleccion_nombres'])->toBe(['lunes', 'miércoles', 'viernes'])
        ->and($fila['puntos_esperados'])->toBe(51)
        ->and($fila['puntos_count'])->toBe(3)
        ->and($fila['puntos_activos_count'])->toBe(2)
        ->and($fila['dias_coinciden_con_periodicidad'])->toBeTrue();
});

test('crea un sector normalizando los dias y valida los datos', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/sectores', [
            'nombre' => '  Sector   Nuevo ',
            'dias_recoleccion' => [4, 2],
            'periodicidad_semanal' => 2,
            'puntos_esperados' => 10,
        ])
        ->assertCreated()
        ->assertJsonPath('data.nombre', 'Sector Nuevo')
        ->assertJsonPath('data.dias_recoleccion', [2, 4])
        ->assertJsonPath('data.puntos_count', 0);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/sectores', [
            'nombre' => 'Sector Nuevo',
            'dias_recoleccion' => [],
            'periodicidad_semanal' => 0,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nombre', 'dias_recoleccion', 'periodicidad_semanal']);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/sectores', [
            'nombre' => 'Otro',
            'dias_recoleccion' => [2, 8, 2],
            'periodicidad_semanal' => 2,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['dias_recoleccion.1', 'dias_recoleccion.0']);
});

test('cambiar los dias de un sector recalcula la vigencia del estado de sus puntos', function () {
    // Lunes 23-11-2026 10:00 en Chile (13:00 UTC).
    Carbon::setTestNow(Carbon::parse('2026-11-23 13:00:00', 'UTC'));

    $sector = Sector::factory()->dias([2, 4])->create();
    $punto = Punto::factory()->medio()->create([
        'sector_id' => $sector->id,
        'origen_actualizacion' => Punto::ORIGEN_MANUAL,
        'ultima_actualizacion_estado' => now(),
    ]);
    // Martes 24-11 a las 23:59:59 de Chile (UTC-3) = miércoles 02:59:59 UTC.
    expect($punto->estado_vigente_hasta?->toDateTimeString())->toBe('2026-11-25 02:59:59');

    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/v1/sectores/{$sector->id}", ['dias_recoleccion' => [5], 'periodicidad_semanal' => 1])
        ->assertOk()
        ->assertJsonPath('data.dias_recoleccion', [5]);

    // Ahora el siguiente día es el viernes 27-11.
    expect($punto->fresh()->estado_vigente_hasta?->toDateTimeString())->toBe('2026-11-28 02:59:59');

    Carbon::setTestNow();
});

test('no se puede desactivar un sector con puntos activos; sin ellos es una baja logica', function () {
    $sector = Sector::factory()->create();
    $punto = Punto::factory()->create(['sector_id' => $sector->id]);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/sectores/{$sector->id}")
        ->assertStatus(409);

    $punto->update(['activo' => false]);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/sectores/{$sector->id}")
        ->assertOk()
        ->assertJsonPath('data.activo', false);

    expect(Sector::find($sector->id))->not->toBeNull();
});

test('un chofer recibe 403 y un invitado 401 en todos los endpoints de sectores', function () {
    $sector = Sector::factory()->create();
    $chofer = Chofer::factory()->create()->user;

    $peticiones = [
        ['getJson', '/api/v1/sectores'],
        ['postJson', '/api/v1/sectores'],
        ['getJson', "/api/v1/sectores/{$sector->id}"],
        ['putJson', "/api/v1/sectores/{$sector->id}"],
        ['deleteJson', "/api/v1/sectores/{$sector->id}"],
    ];

    foreach ($peticiones as [$metodo, $url]) {
        $this->{$metodo}($url)->assertUnauthorized();
        $this->actingAs($chofer, 'sanctum')->{$metodo}($url)->assertForbidden();
        app('auth')->forgetGuards();
    }
});
