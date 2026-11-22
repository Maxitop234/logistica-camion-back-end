<?php

use App\Models\Administrador;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\DesvioRuta;
use App\Models\Punto;
use App\Models\Ruta;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->adminUser = User::factory()->create(['role' => 'administrador']);
    Administrador::factory()->create(['user_id' => $this->adminUser->id]);
    $this->chofer = Chofer::factory()->create();
    $this->otro = Chofer::factory()->create();
    $this->camion = Camion::factory()->create(['estado_operativo' => 'disponible', 'chofer_actual_id' => $this->chofer->id]);
    $this->ruta = Ruta::create([
        'camion_id' => $this->camion->id, 'chofer_id' => $this->chofer->id,
        'fecha_programada' => now()->toDateString(), 'estado' => Ruta::ESTADO_EN_CURSO,
        'total_puntos_evaluados' => 0, 'puntos_incluidos_count' => 0, 'puntos_excluidos_count' => 0,
    ]);
});

test('un chofer no accede a endpoints de administrador', function (string $metodo, string $url) {
    $this->actingAs($this->chofer->user, 'sanctum')->json($metodo, $url)->assertForbidden();
})->with([
    ['GET', '/api/v1/rutas'], ['POST', '/api/v1/rutas/generar'], ['GET', '/api/v1/gps/flota'],
    ['GET', '/api/v1/camiones'], ['GET', '/api/v1/choferes'], ['GET', '/api/v1/puntos'],
    ['GET', '/api/v1/kpis/dashboard'], ['PUT', '/api/v1/kpis/parametros'], ['GET', '/api/v1/dispositivos'],
    ['GET', '/api/v1/notificaciones/puntos'], ['GET', '/api/v1/desvios/calles-problematicas'],
]);

test('un chofer no ve ni modifica rutas ajenas', function () {
    $this->actingAs($this->otro->user, 'sanctum')->getJson("/api/v1/rutas/{$this->ruta->id}/traza")->assertForbidden();
    $this->actingAs($this->otro->user, 'sanctum')->getJson("/api/v1/rutas/{$this->ruta->id}/desvios")->assertForbidden();
    $this->actingAs($this->otro->user, 'sanctum')->postJson("/api/v1/rutas/{$this->ruta->id}/finalizar")->assertForbidden();
    $this->actingAs($this->otro->user, 'sanctum')->postJson("/api/v1/rutas/{$this->ruta->id}/sincronizar-lote", [
        'recolecciones' => [['punto_id' => Punto::factory()->create()->id, 'sync_uuid' => (string) Str::uuid(), 'hora_real_recoleccion' => now()->toIso8601String()]],
    ])->assertForbidden();
    expect($this->ruta->fresh()->estado)->toBe(Ruta::ESTADO_EN_CURSO);
});

test('un chofer no registra motivo en desvíos ajenos ni los ve en su lista', function () {
    $d = DesvioRuta::create([
        'ruta_id' => $this->ruta->id, 'chofer_id' => $this->chofer->id,
        'inicio_en' => now()->subHour(), 'fin_en' => now(), 'distancia_m' => 100, 'max_separacion_m' => 60,
        'geometria_real' => [], 'geometria_planificada' => [], 'clave_tramo' => 'x', 'centro_latitud' => -38.7, 'centro_longitud' => -72.6,
    ]);
    $this->actingAs($this->otro->user, 'sanctum')->putJson("/api/v1/chofer/desvios/{$d->id}/motivo", ['motivo_codigo' => 'otro'])->assertForbidden();
    $this->actingAs($this->otro->user, 'sanctum')->getJson('/api/v1/chofer/desvios?todos=1')->assertOk()->assertJsonCount(0, 'data');
});

test('un chofer desactivado no opera ni un administrador desactivado entra', function () {
    $this->chofer->user->update(['is_active' => false]);
    $this->actingAs($this->chofer->user, 'sanctum')->getJson('/api/v1/chofer/ruta-activa')->assertForbidden();
    $this->actingAs($this->chofer->user, 'sanctum')->postJson("/api/v1/rutas/{$this->ruta->id}/finalizar")->assertForbidden();
    $this->actingAs($this->chofer->user, 'sanctum')->postJson('/api/v1/gps/ping', ['latitud' => -38.7, 'longitud' => -72.6])->assertForbidden();
    $this->adminUser->update(['is_active' => false]);
    $this->actingAs($this->adminUser, 'sanctum')->getJson('/api/v1/rutas')->assertForbidden();
});

test('entradas inválidas responden 4xx y no 500', function (string $metodo, string $url, array $cuerpo) {
    $r = $this->actingAs($this->adminUser, 'sanctum')->json($metodo, $url, $cuerpo);
    expect($r->status())->toBeLessThan(500);
})->with([
    ['GET', '/api/v1/rutas?fecha_programada=abc', []],
    ['GET', '/api/v1/rutas?estado[]=x', []],
    ['GET', '/api/v1/rutas?chofer_id=abc', []],
    ['GET', '/api/v1/kpis/dashboard?desde=zzz&hasta=qqq', []],
    ['GET', '/api/v1/kpis/dashboard?agrupacion=zzz', []],
    ['GET', '/api/v1/kpis/alertas?desde=2026-13-45', []],
    ['GET', '/api/v1/desvios/calles-problematicas?dias=abc&umbral=-5', []],
    ['GET', '/api/v1/choferes/99999', []],
    ['GET', '/api/v1/puntos/99999', []],
    ['GET', '/api/v1/rutas/99999/traza', []],
    ['POST', '/api/v1/rutas/generar', ['camion_id' => 'x', 'chofer_id' => [], 'fecha_programada' => '2026-02-31']],
    ['POST', '/api/v1/rutas/generar', ['camion_id' => 1, 'chofer_id' => 1, 'fecha_programada' => '2026-11-22', 'origen' => ['latitud' => 999, 'longitud' => 0]]],
    ['POST', '/api/v1/puntos', ['nombre' => str_repeat('a', 100000), 'latitud' => 999, 'longitud' => 999]],
    ['POST', '/api/v1/puntos', ['nombre' => '', 'latitud' => 'x', 'longitud' => null]],
    ['POST', '/api/v1/camiones', ['patente' => str_repeat('A', 5000), 'anio' => 'abc']],
    ['POST', '/api/v1/choferes', ['nombre' => '', 'rut' => 'abc', 'email' => 'no']],
    ['POST', '/api/v1/sectores', ['nombre' => str_repeat('a', 100000)]],
    ['GET', '/api/v1/puntos?per_page=abc&estado[]=x&sector_id=zz', []],
    ['GET', '/api/v1/geocodificacion/inversa?lat=abc&lng=1', []],
    ['GET', '/api/v1/dispositivos?estado=zzz', []],
    ['POST', '/api/v1/dispositivos/aprobar', ['codigo' => str_repeat('x', 100000)]],
    ['POST', '/api/v1/dispositivos/99999/calibrar-vacio', []],
    ['PUT', '/api/v1/kpis/parametros', ['parametros' => 'x']],
    ['GET', '/api/v1/kpis/exportar?formato=zzz&desde=zzz', []],
]);

test('el chofer con entradas inválidas recibe 4xx y no 500', function (string $metodo, string $url, array $cuerpo) {
    $r = $this->actingAs($this->chofer->user, 'sanctum')->json($metodo, $url, $cuerpo);
    expect($r->status())->toBeLessThan(500);
})->with([
    ['POST', '/api/v1/gps/ping', ['latitud' => 999, 'longitud' => 'x']],
    ['POST', '/api/v1/gps/ping', ['pings' => 'x']],
    ['POST', '/api/v1/gps/ping', ['pings' => [['latitud' => -38.7, 'longitud' => -72.6, 'capturado_en' => 'ayer']]]],
    ['POST', '/api/v1/rutas/1/sincronizar-lote', ['recolecciones' => [['punto_id' => 'a', 'sync_uuid' => 'x', 'hora_real_recoleccion' => 'zz']]]],
    ['POST', '/api/v1/rutas/99999/iniciar', []],
    ['POST', '/api/v1/chofer/solicitudes-punto', ['nombre' => str_repeat('a', 100000), 'latitud' => 999]],
    ['POST', '/api/v1/chofer/solicitudes-punto', []],
    ['PUT', '/api/v1/chofer/desvios/99999/motivo', ['motivo_codigo' => 'x']],
    ['PUT', '/api/v1/chofer/avatar', ['avatar' => ['x']]],
    ['POST', '/api/v1/chofer/licencia', []],
    ['POST', '/api/v1/chofer/solicitud-reactivacion', ['mensaje' => str_repeat('a', 100000)]],
    ['POST', '/api/v1/chofer/notificaciones/99999/leer', []],
    ['GET', '/api/v1/chofer/puntos?lat=abc', []],
]);

test('el administrador no desactiva un camión en ruta por la vía de actualización', function () {
    $this->camion->update(['estado_operativo' => 'en_ruta']);
    $this->actingAs($this->adminUser, 'sanctum')
        ->putJson("/api/v1/camiones/{$this->camion->id}", ['estado_operativo' => 'inactivo'])
        ->assertStatus(409);
    expect($this->camion->fresh()->estado_operativo)->toBe('en_ruta');
});

test('no se inicia una ruta con el camión inactivo o en mantenimiento', function () {
    $this->ruta->update(['estado' => Ruta::ESTADO_PLANIFICADA]);
    $this->camion->update(['estado_operativo' => 'mantenimiento']);
    $this->actingAs($this->chofer->user, 'sanctum')->postJson("/api/v1/rutas/{$this->ruta->id}/iniciar")->assertStatus(422);
    expect($this->camion->fresh()->estado_operativo)->toBe('mantenimiento');
});
